<?php

declare(strict_types=1);

namespace Hexis\AuditBundle\Tests\Integration\EventListener;

use Doctrine\ORM\EntityManager;
use Hexis\AuditBundle\Doctrine\AuditableRegistry;
use Hexis\AuditBundle\Domain\AuditContext;
use Hexis\AuditBundle\Domain\AuditEvent;
use Hexis\AuditBundle\Domain\ContextCollector;
use Hexis\AuditBundle\Domain\EventType;
use Hexis\AuditBundle\EventListener\DoctrineAuditListener;
use Hexis\AuditBundle\Storage\AuditWriter;
use Hexis\AuditBundle\Storage\BufferedAuditWriter;
use Hexis\AuditBundle\Storage\DoctrineAuditWriter;
use Hexis\AuditBundle\Tests\Integration\Fixtures\AuditedEntity;
use Hexis\AuditBundle\Tests\Integration\Fixtures\NonAuditedEntity;
use Hexis\AuditBundle\Tests\Integration\OrmFactory;
use Hexis\AuditBundle\Tests\Integration\SchemaFactory;
use PHPUnit\Framework\TestCase;

final class DoctrineAuditListenerTest extends TestCase
{
    private EntityManager $em;
    private BufferedAuditWriter $buffer;
    /** @var list<AuditEvent> */
    private array $directWrites = [];

    protected function setUp(): void
    {
        $this->em = OrmFactory::create();

        $writer = new DoctrineAuditWriter(
            $this->em->getConnection(),
            SchemaFactory::TABLE,
        );

        $this->buffer = new BufferedAuditWriter($writer);

        $listener = new DoctrineAuditListener(
            registry: new AuditableRegistry(),
            writer: $this->buffer,
            contextCollector: $this->stubContextCollector(),
        );

        $this->em->getEventManager()->addEventListener(['onFlush', 'postFlush', 'onClear'], $listener);
    }

    public function testAuditsInsertOfAuditedEntity(): void
    {
        $entity = new AuditedEntity();
        $entity->name = 'Alice';
        $this->em->persist($entity);
        $this->em->flush();

        $this->buffer->flush();

        self::assertSame(1, $this->auditRowCount());
        $row = $this->em->getConnection()->fetchAssociative('SELECT * FROM hexis_audit_log');
        self::assertSame(EventType::ENTITY_INSERT->value, $row['event_type']);
        self::assertSame(AuditedEntity::class, $row['target_class']);
    }

    public function testAuditsUpdateWithDiff(): void
    {
        $entity = new AuditedEntity();
        $entity->name = 'Alice';
        $this->em->persist($entity);
        $this->em->flush();
        $this->buffer->flush();

        $entity->name = 'Alicia';
        $entity->email = 'alicia@example.com';
        $this->em->flush();
        $this->buffer->flush();

        $row = $this->em->getConnection()->fetchAssociative(
            'SELECT * FROM hexis_audit_log WHERE event_type = ?',
            [EventType::ENTITY_UPDATE->value],
        );
        self::assertNotFalse($row);

        $diff = json_decode($row['diff'], true);
        self::assertSame(['old' => 'Alice', 'new' => 'Alicia'], $diff['name']);
        self::assertSame(['old' => null, 'new' => 'alicia@example.com'], $diff['email']);
    }

    public function testAuditsDelete(): void
    {
        $entity = new AuditedEntity();
        $entity->name = 'Alice';
        $this->em->persist($entity);
        $this->em->flush();
        $this->buffer->flush();

        $this->em->remove($entity);
        $this->em->flush();
        $this->buffer->flush();

        $row = $this->em->getConnection()->fetchAssociative(
            'SELECT * FROM hexis_audit_log WHERE event_type = ?',
            [EventType::ENTITY_DELETE->value],
        );
        self::assertNotFalse($row);
        self::assertSame(AuditedEntity::class, $row['target_class']);
    }

    public function testDoesNotAuditNonAuditedEntity(): void
    {
        $entity = new NonAuditedEntity();
        $entity->name = 'Bob';
        $this->em->persist($entity);
        $this->em->flush();
        $this->buffer->flush();

        self::assertSame(0, $this->auditRowCount());
    }

    public function testBundleOwnEntitiesSkipped(): void
    {
        // Even if a host accidentally listed the bundle's own entity as auditable in YAML,
        // the registry must refuse. Cover the case with a registry built that way.
        $hostileRegistry = new AuditableRegistry([
            \Hexis\AuditBundle\Entity\AuditLog::class => ['mode' => 'full'],
        ]);

        self::assertFalse($hostileRegistry->isAudited(\Hexis\AuditBundle\Entity\AuditLog::class));
    }

    public function testIgnoreFieldsExcludedFromDiff(): void
    {
        // Build a dedicated ORM + listener with the YAML override active; don't share the default setUp listener.
        $em = OrmFactory::create();
        $writer = new DoctrineAuditWriter($em->getConnection(), SchemaFactory::TABLE);
        $buffer = new BufferedAuditWriter($writer);
        $registry = new AuditableRegistry([
            AuditedEntity::class => ['mode' => 'changed_fields', 'ignore_fields' => ['email']],
        ]);
        $listener = new DoctrineAuditListener(
            registry: $registry,
            writer: $buffer,
            contextCollector: $this->stubContextCollector(),
        );
        $em->getEventManager()->addEventListener(['onFlush', 'postFlush', 'onClear'], $listener);

        $entity = new AuditedEntity();
        $entity->name = 'Alice';
        $em->persist($entity);
        $em->flush();
        $buffer->flush();

        $em->getConnection()->executeStatement('DELETE FROM hexis_audit_log');

        $entity->name = 'Alicia';
        $entity->email = 'alicia@example.com';
        $em->flush();
        $buffer->flush();

        $row = $em->getConnection()->fetchAssociative(
            'SELECT * FROM hexis_audit_log WHERE event_type = ?',
            [EventType::ENTITY_UPDATE->value],
        );
        self::assertNotFalse($row);
        $diff = json_decode($row['diff'], true);
        self::assertArrayHasKey('name', $diff);
        self::assertArrayNotHasKey('email', $diff, 'email is in ignore_fields');
    }

    private function auditRowCount(): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM hexis_audit_log');
    }

    private function stubContextCollector(): ContextCollector
    {
        return new class extends ContextCollector {
            public function __construct()
            {
            }
            public function collectContext(): AuditContext
            {
                return AuditContext::empty();
            }
            public function collectActor(?string $firewall = null): \Hexis\AuditBundle\Domain\Actor
            {
                return new \Hexis\AuditBundle\Domain\Actor(id: 'test-user');
            }
        };
    }
}
