<?php

declare(strict_types=1);

namespace Hexis\AuditBundle\Tests\Integration\EventListener;

use Doctrine\ORM\EntityManager;
use Gedmo\SoftDeleteable\SoftDeleteableListener;
use Hexis\AuditBundle\Doctrine\AuditableRegistry;
use Hexis\AuditBundle\Domain\Actor;
use Hexis\AuditBundle\Domain\AuditContext;
use Hexis\AuditBundle\Domain\ContextCollector;
use Hexis\AuditBundle\Domain\EventType;
use Hexis\AuditBundle\EventListener\DoctrineAuditListener;
use Hexis\AuditBundle\Serializer\AuditSnapshotNormalizer;
use Hexis\AuditBundle\Storage\BufferedAuditWriter;
use Hexis\AuditBundle\Storage\DoctrineAuditWriter;
use Hexis\AuditBundle\Tests\Integration\Fixtures\SoftDeletableEntity;
use Hexis\AuditBundle\Tests\Integration\OrmFactory;
use Hexis\AuditBundle\Tests\Integration\SchemaFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Gedmo SoftDeleteable turns remove() into an UPDATE inside its own onFlush. Whichever of the two
 * onFlush listeners runs first, the audit log must show exactly one soft delete.
 */
final class DoctrineAuditListenerSoftDeleteTest extends TestCase
{
    private const AUDIT_EVENTS = ['onFlush', 'postFlush', 'onClear', 'preSoftDelete'];

    private EntityManager $em;
    private BufferedAuditWriter $buffer;

    /**
     * @return iterable<string, array{bool}>
     */
    public static function listenerOrders(): iterable
    {
        yield 'audit listener before Gedmo' => [true];
        yield 'Gedmo before the audit listener' => [false];
    }

    #[DataProvider('listenerOrders')]
    public function testSoftDeleteIsRecordedOnceAsADelete(bool $auditFirst): void
    {
        $entity = $this->createEntity($auditFirst);

        $this->em->remove($entity);
        $this->em->flush();
        $this->buffer->flush();

        $rows = $this->auditRows();
        self::assertCount(1, $rows);
        self::assertSame(EventType::ENTITY_DELETE->value, $rows[0]['event_type']);
        self::assertSame(DoctrineAuditListener::ACTION_SOFT_DELETE, $rows[0]['action']);
        self::assertSame(SoftDeletableEntity::class, $rows[0]['target_class']);
        self::assertSame((string) $entity->id, $rows[0]['target_id']);
        self::assertSame('doctrine', $rows[0]['source']);
        self::assertNotNull(
            $this->em->getConnection()->fetchOne('SELECT deleted_at FROM test_soft_deletable WHERE id = ?', [$entity->id]),
            'the row is soft deleted, not removed',
        );
    }

    #[DataProvider('listenerOrders')]
    public function testSoftDeletePreImageIsTheEntityBeforeTheDelete(bool $auditFirst): void
    {
        $entity = $this->createEntity($auditFirst);

        $this->em->remove($entity);
        $this->em->flush();
        $this->buffer->flush();

        $preImage = json_decode($this->auditRows()[0]['pre_image'], true);
        self::assertSame('Alice', $preImage['name']);
        self::assertArrayHasKey('deletedAt', $preImage);
        self::assertNull($preImage['deletedAt']);
    }

    #[DataProvider('listenerOrders')]
    public function testRemovingASoftDeletedEntityAgainIsAHardDelete(bool $auditFirst): void
    {
        $entity = $this->createEntity($auditFirst);
        $id = $entity->id;
        $this->em->remove($entity);
        $this->em->flush();

        $this->em->remove($entity);
        $this->em->flush();
        $this->buffer->flush();

        $rows = $this->auditRows();
        self::assertCount(2, $rows);
        self::assertSame(EventType::ENTITY_DELETE->value, $rows[1]['event_type']);
        self::assertNull($rows[1]['action']);
        self::assertSame((string) $id, $rows[1]['target_id']);
        self::assertFalse($this->em->getConnection()->fetchOne('SELECT id FROM test_soft_deletable WHERE id = ?', [$id]));
    }

    #[DataProvider('listenerOrders')]
    public function testRestoringASoftDeletedEntityIsAnUpdate(bool $auditFirst): void
    {
        $entity = $this->createEntity($auditFirst);
        $id = $entity->id;
        $this->em->remove($entity);
        $this->em->flush();
        $this->buffer->flush();
        $this->em->clear();

        $restored = $this->em->find(SoftDeletableEntity::class, $id);
        $restored->deletedAt = null;
        $this->em->flush();
        $this->buffer->flush();

        $rows = $this->auditRows();
        self::assertCount(2, $rows);
        self::assertSame(EventType::ENTITY_UPDATE->value, $rows[1]['event_type']);
        self::assertNull(json_decode($rows[1]['diff'], true)['deletedAt']['new']);
    }

    private function createEntity(bool $auditFirst): SoftDeletableEntity
    {
        $this->em = OrmFactory::create();
        $this->buffer = new BufferedAuditWriter(new DoctrineAuditWriter($this->em->getConnection(), SchemaFactory::TABLE));
        $audit = new DoctrineAuditListener(
            registry: new AuditableRegistry(),
            writer: $this->buffer,
            contextCollector: $this->stubContextCollector(),
            snapshotNormalizer: new AuditSnapshotNormalizer(),
        );
        $gedmo = new SoftDeleteableListener();

        $events = $this->em->getEventManager();
        if ($auditFirst) {
            $events->addEventListener(self::AUDIT_EVENTS, $audit);
            $events->addEventSubscriber($gedmo);
        } else {
            $events->addEventSubscriber($gedmo);
            $events->addEventListener(self::AUDIT_EVENTS, $audit);
        }

        $entity = new SoftDeletableEntity();
        $entity->name = 'Alice';
        $this->em->persist($entity);
        $this->em->flush();
        $this->buffer->flush();
        $this->em->getConnection()->executeStatement('DELETE FROM hexis_audit_log');

        return $entity;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function auditRows(): array
    {
        return $this->em->getConnection()->fetchAllAssociative('SELECT * FROM hexis_audit_log ORDER BY id');
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

            public function collectActor(?string $firewall = null): Actor
            {
                return new Actor(id: 'test-user');
            }
        };
    }
}
