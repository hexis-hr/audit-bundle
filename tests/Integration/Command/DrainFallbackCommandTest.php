<?php

declare(strict_types=1);

namespace Hexis\AuditBundle\Tests\Integration\Command;

use Doctrine\DBAL\Connection;
use Hexis\AuditBundle\Command\DrainFallbackCommand;
use Hexis\AuditBundle\Domain\Actor;
use Hexis\AuditBundle\Domain\AuditContext;
use Hexis\AuditBundle\Domain\AuditEvent;
use Hexis\AuditBundle\Domain\EventType;
use Hexis\AuditBundle\Domain\Snapshot;
use Hexis\AuditBundle\Domain\Target;
use Hexis\AuditBundle\Storage\AuditWriter;
use Hexis\AuditBundle\Storage\DoctrineAuditWriter;
use Hexis\AuditBundle\Tests\Integration\SchemaFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class DrainFallbackCommandTest extends TestCase
{
    private Connection $connection;
    /** @var list<AuditEvent> */
    private array $replayedToPrimary = [];

    protected function setUp(): void
    {
        $this->connection = SchemaFactory::create();
        $this->replayedToPrimary = [];
    }

    public function testReplaysPendingRows(): void
    {
        $fallback = new DoctrineAuditWriter(
            $this->connection,
            SchemaFactory::TABLE,
            DoctrineAuditWriter::SOURCE_FALLBACK,
        );

        $fallback->write($this->buildEvent(EventType::LOGIN));
        $fallback->write($this->buildEvent(EventType::ENTITY_UPDATE));

        self::assertSame(2, $this->pendingCount());

        $tester = $this->buildCommand();
        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertCount(2, $this->replayedToPrimary);
        self::assertSame(0, $this->pendingCount(), 'rows must be marked replayed');

        $row = $this->connection->fetchAssociative('SELECT * FROM hexis_audit_log LIMIT 1');
        self::assertSame('replayed', $row['source_of_truth']);
        self::assertNull($row['pending_replay_at']);
    }

    public function testDryRunDoesNotModify(): void
    {
        $fallback = new DoctrineAuditWriter($this->connection, SchemaFactory::TABLE, DoctrineAuditWriter::SOURCE_FALLBACK);
        $fallback->write($this->buildEvent(EventType::LOGIN));

        $tester = $this->buildCommand();
        $tester->execute(['--dry-run' => true]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertSame(1, $this->pendingCount(), 'dry-run must leave markers untouched');
        self::assertCount(0, $this->replayedToPrimary);
    }

    public function testFailedReplayLeavesRowPending(): void
    {
        $fallback = new DoctrineAuditWriter($this->connection, SchemaFactory::TABLE, DoctrineAuditWriter::SOURCE_FALLBACK);
        $fallback->write($this->buildEvent(EventType::LOGIN));

        $failingPrimary = new class implements AuditWriter {
            public function write(AuditEvent $event): void
            {
                throw new \RuntimeException('primary still down');
            }
            public function writeBatch(iterable $events): void
            {
            }
        };

        $command = new DrainFallbackCommand($this->connection, SchemaFactory::TABLE, $failingPrimary);
        $app = new Application();
        $app->add($command);
        $tester = new CommandTester($app->find('audit:drain-fallback'));

        $tester->execute([]);

        // command returns FAILURE when a row failed to replay
        self::assertNotSame(0, $tester->getStatusCode());
        self::assertSame(1, $this->pendingCount(), 'failed replay must stay pending for next drain');
    }

    public function testLimitCapsRowsProcessed(): void
    {
        $fallback = new DoctrineAuditWriter($this->connection, SchemaFactory::TABLE, DoctrineAuditWriter::SOURCE_FALLBACK);
        for ($i = 0; $i < 5; $i++) {
            $fallback->write($this->buildEvent(EventType::LOGIN));
        }

        $tester = $this->buildCommand();
        $tester->execute(['--limit' => '2']);

        self::assertCount(2, $this->replayedToPrimary);
        self::assertSame(3, $this->pendingCount(), 'remaining rows stay pending');
    }

    private function buildCommand(): CommandTester
    {
        $captor = $this;
        $primary = new class($captor) implements AuditWriter {
            public function __construct(private readonly DrainFallbackCommandTest $captor)
            {
            }
            public function write(AuditEvent $event): void
            {
                $this->captor->capture($event);
            }
            public function writeBatch(iterable $events): void
            {
                foreach ($events as $e) {
                    $this->captor->capture($e);
                }
            }
        };

        $command = new DrainFallbackCommand($this->connection, SchemaFactory::TABLE, $primary);
        $app = new Application();
        $app->add($command);
        return new CommandTester($app->find('audit:drain-fallback'));
    }

    public function capture(AuditEvent $event): void
    {
        $this->replayedToPrimary[] = $event;
    }

    private function pendingCount(): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM hexis_audit_log WHERE source_of_truth = ? AND pending_replay_at IS NOT NULL',
            [DoctrineAuditWriter::SOURCE_FALLBACK],
        );
    }

    private function buildEvent(EventType $type): AuditEvent
    {
        return new AuditEvent(
            type: $type,
            actor: new Actor(id: 'user-42'),
            target: new Target(),
            snapshot: Snapshot::none(),
            context: AuditContext::empty(),
        );
    }
}
