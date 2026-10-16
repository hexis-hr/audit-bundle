<?php

declare(strict_types=1);

namespace Hexis\AuditBundle\Tests\Integration\Kernel;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Hexis\AuditBundle\Domain\EventType;
use Hexis\AuditBundle\Entity\AuditLog;
use Hexis\AuditBundle\EventListener\FlushBufferListener;
use Hexis\AuditBundle\EventListener\SecurityAuditSubscriber;
use Hexis\AuditBundle\Storage\AuditReader;
use Hexis\AuditBundle\Storage\AuditWriter;
use Hexis\AuditBundle\Storage\BufferedAuditWriter;
use Hexis\AuditBundle\Storage\DoctrineAuditReader;
use Hexis\AuditBundle\Storage\DoctrineAuditWriter;
use Hexis\AuditBundle\Storage\ElasticsearchAuditReader;
use Hexis\AuditBundle\Tests\Integration\Fixtures\AuditedEntity;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * Boots the real bundle in a Symfony kernel (FrameworkBundle, DoctrineBundle, DoctrineMigrationsBundle)
 * and drives it end to end: container, console, the bundle's migrations, the Doctrine listener,
 * the kernel.terminate flush and the reader.
 */
final class AuditBundleKernelTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    public function testContainerCompilesAndExposesTheBufferedWriter(): void
    {
        $container = self::bootKernel()->getContainer();

        $writer = $container->get(AuditWriter::class);

        self::assertInstanceOf(BufferedAuditWriter::class, $writer);
        self::assertSame($writer, $container->get(BufferedAuditWriter::class));
    }

    public function testReaderListenersAndMappingAreWired(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        self::assertInstanceOf(DoctrineAuditReader::class, $container->get(AuditReader::class));

        $dispatcher = $container->get('event_dispatcher');
        self::assertContains(SecurityAuditSubscriber::class, $this->listenerClasses($dispatcher->getListeners(LoginSuccessEvent::class)));
        self::assertContains(FlushBufferListener::class, $this->listenerClasses($dispatcher->getListeners(KernelEvents::TERMINATE)));

        $em = $container->get('doctrine.orm.entity_manager');
        self::assertSame('hexis_audit_log', $em->getClassMetadata(AuditLog::class)->getTableName());
    }

    public function testConsoleRegistersTheDoctrineStorageCommands(): void
    {
        $this->bootWithSchema();

        self::assertSame(['audit:migrate-legacy', 'audit:prune'], $this->auditCommandNames());

        $prune = $this->executeCommand('audit:prune', ['--dry-run' => true]);
        self::assertSame(Command::SUCCESS, $prune->getStatusCode());
        self::assertStringContainsString(sprintf('No rows older than %d days', TestKernel::RETENTION_DAYS), $prune->getDisplay());
    }

    public function testInsertThroughTheEntityManagerIsReadBack(): void
    {
        $em = $this->bootWithSchema();

        $entity = new AuditedEntity();
        $entity->name = 'Alice';
        $em->persist($entity);
        $em->flush();
        $this->terminate();

        $result = $this->reader()->list(['target_class' => AuditedEntity::class], 10, 0);

        self::assertSame(1, $result['total']);
        $row = $result['rows'][0];
        self::assertSame(EventType::ENTITY_INSERT->value, $row['event_type']);
        self::assertSame((string) $entity->id, $row['target_id']);
        self::assertSame(DoctrineAuditWriter::SOURCE_PRIMARY, $row['source_of_truth']);
        self::assertEquals($row, $this->reader()->get($row['event_id']));
    }

    public function testUpdateThroughTheEntityManagerRecordsTheChange(): void
    {
        $em = $this->bootWithSchema();

        $entity = new AuditedEntity();
        $entity->name = 'Alice';
        $em->persist($entity);
        $em->flush();
        $this->terminate();

        $entity->name = 'Alicia';
        $em->flush();
        $this->terminate();

        $result = $this->reader()->list(['event_type' => EventType::ENTITY_UPDATE->value], 10, 0);

        self::assertSame(1, $result['total']);
        $row = $result['rows'][0];
        self::assertSame((string) $entity->id, $row['target_id']);
        self::assertSame(['name' => ['old' => 'Alice', 'new' => 'Alicia']], $row['diff']);
    }

    public function testElasticsearchPrimaryRegistersItsCommands(): void
    {
        $this->bootWithSchema(TestKernel::ENV_ELASTICSEARCH);

        self::assertInstanceOf(ElasticsearchAuditReader::class, self::getContainer()->get(AuditReader::class));
        self::assertSame(
            ['audit:drain-fallback', 'audit:install-elasticsearch-template', 'audit:migrate-legacy', 'audit:prune'],
            $this->auditCommandNames(),
        );

        $install = $this->executeCommand('audit:install-elasticsearch-template');
        self::assertSame(Command::SUCCESS, $install->getStatusCode());
        self::assertArrayHasKey('audit', $this->elasticsearch()->templates);
    }

    public function testFallbackRowIsDrainedIntoElasticsearch(): void
    {
        $em = $this->bootWithSchema(TestKernel::ENV_ELASTICSEARCH);
        $elasticsearch = $this->elasticsearch();

        $elasticsearch->failing = true;
        $entity = new AuditedEntity();
        $entity->name = 'Alice';
        $em->persist($entity);
        $em->flush();
        $this->terminate();

        self::assertSame([], $elasticsearch->documents);
        self::assertSame(1, $this->countRows(DoctrineAuditWriter::SOURCE_FALLBACK));

        $elasticsearch->failing = false;
        $drain = $this->executeCommand('audit:drain-fallback');

        self::assertSame(Command::SUCCESS, $drain->getStatusCode(), $drain->getDisplay());
        self::assertCount(1, $elasticsearch->documents);
        $document = array_values($elasticsearch->documents)[0];
        self::assertSame(EventType::ENTITY_INSERT->value, $document['event_type']);
        self::assertSame(0, $this->countRows(DoctrineAuditWriter::SOURCE_FALLBACK));
        self::assertSame(1, $this->countRows(DoctrineAuditWriter::SOURCE_REPLAYED));
    }

    /**
     * Boots the kernel, runs the bundle's migrations through the console and creates the fixture table.
     */
    private function bootWithSchema(string $environment = TestKernel::ENV_DOCTRINE): EntityManagerInterface
    {
        self::bootKernel(['environment' => $environment]);

        $migrate = $this->executeCommand('doctrine:migrations:migrate');
        self::assertSame(Command::SUCCESS, $migrate->getStatusCode(), $migrate->getDisplay());

        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get('doctrine.orm.entity_manager');
        (new SchemaTool($em))->createSchema([$em->getClassMetadata(AuditedEntity::class)]);

        return $em;
    }

    /**
     * @param array<string, mixed> $input
     */
    private function executeCommand(string $name, array $input = []): CommandTester
    {
        $tester = new CommandTester((new Application(self::$kernel))->find($name));
        $tester->execute($input, ['interactive' => false]);

        return $tester;
    }

    /**
     * @return list<string>
     */
    private function auditCommandNames(): array
    {
        $names = array_keys((new Application(self::$kernel))->all('audit'));
        sort($names);

        return $names;
    }

    /**
     * The end of the request: FlushBufferListener hands the buffered events to the writer.
     */
    private function terminate(): void
    {
        self::$kernel->terminate(Request::create('/'), new Response());
    }

    private function reader(): AuditReader
    {
        return self::getContainer()->get(AuditReader::class);
    }

    private function elasticsearch(): InMemoryElasticsearchClient
    {
        return self::getContainer()->get(InMemoryElasticsearchClient::class);
    }

    private function countRows(string $sourceOfTruth): int
    {
        /** @var Connection $connection */
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');

        return (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM hexis_audit_log WHERE source_of_truth = ?',
            [$sourceOfTruth],
        );
    }

    /**
     * @param iterable<callable|array{0: object, 1: string}> $listeners
     * @return list<class-string>
     */
    private function listenerClasses(iterable $listeners): array
    {
        $classes = [];
        foreach ($listeners as $listener) {
            if (\is_array($listener) && \is_object($listener[0])) {
                $classes[] = $listener[0]::class;
            }
        }

        return $classes;
    }
}
