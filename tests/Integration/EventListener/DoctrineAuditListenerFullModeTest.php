<?php

declare(strict_types=1);

namespace Hexis\AuditBundle\Tests\Integration\EventListener;

use Doctrine\ORM\EntityManager;
use Hexis\AuditBundle\Doctrine\AuditableRegistry;
use Hexis\AuditBundle\Domain\AuditContext;
use Hexis\AuditBundle\Domain\ContextCollector;
use Hexis\AuditBundle\Domain\EventType;
use Hexis\AuditBundle\EventListener\DoctrineAuditListener;
use Hexis\AuditBundle\Serializer\AuditSnapshotNormalizer;
use Hexis\AuditBundle\Storage\BufferedAuditWriter;
use Hexis\AuditBundle\Storage\DoctrineAuditWriter;
use Hexis\AuditBundle\Tests\Integration\Fixtures\FullAuditedEntity;
use Hexis\AuditBundle\Tests\Integration\OrmFactory;
use Hexis\AuditBundle\Tests\Integration\SchemaFactory;
use PHPUnit\Framework\TestCase;

final class DoctrineAuditListenerFullModeTest extends TestCase
{
    private EntityManager $em;
    private BufferedAuditWriter $buffer;

    protected function setUp(): void
    {
        $this->em = OrmFactory::create();

        $writer = new DoctrineAuditWriter($this->em->getConnection(), SchemaFactory::TABLE);
        $this->buffer = new BufferedAuditWriter($writer);

        $listener = new DoctrineAuditListener(
            registry: new AuditableRegistry(),
            writer: $this->buffer,
            contextCollector: $this->stubContextCollector(),
            snapshotNormalizer: new AuditSnapshotNormalizer(),
        );

        $this->em->getEventManager()->addEventListener(['onFlush', 'postFlush', 'onClear'], $listener);
    }

    public function testInsertCapturesPostImage(): void
    {
        $entity = new FullAuditedEntity();
        $entity->name = 'Alice';
        $entity->email = 'alice@example.com';
        $this->em->persist($entity);
        $this->em->flush();
        $this->buffer->flush();

        $row = $this->em->getConnection()->fetchAssociative(
            'SELECT * FROM hexis_audit_log WHERE event_type = ?',
            [EventType::ENTITY_INSERT->value],
        );
        self::assertNotFalse($row);
        self::assertSame('full', $row['snapshot_mode']);
        self::assertNull($row['pre_image']);
        $postImage = json_decode($row['post_image'], true);
        self::assertSame('Alice', $postImage['name']);
        self::assertSame('alice@example.com', $postImage['email']);
    }

    public function testUpdateCapturesPreAndPostImage(): void
    {
        $entity = new FullAuditedEntity();
        $entity->name = 'Alice';
        $entity->email = 'alice@example.com';
        $this->em->persist($entity);
        $this->em->flush();
        $this->buffer->flush();
        $this->em->getConnection()->executeStatement('DELETE FROM hexis_audit_log');

        $entity->name = 'Alicia';
        $this->em->flush();
        $this->buffer->flush();

        $row = $this->em->getConnection()->fetchAssociative(
            'SELECT * FROM hexis_audit_log WHERE event_type = ?',
            [EventType::ENTITY_UPDATE->value],
        );
        self::assertNotFalse($row);
        self::assertSame('full', $row['snapshot_mode']);

        $pre = json_decode($row['pre_image'], true);
        self::assertSame('Alice', $pre['name']);

        $post = json_decode($row['post_image'], true);
        self::assertSame('Alicia', $post['name']);

        $diff = json_decode($row['diff'], true);
        self::assertSame(['old' => 'Alice', 'new' => 'Alicia'], $diff['name']);
    }

    public function testDeleteCapturesPreImage(): void
    {
        $entity = new FullAuditedEntity();
        $entity->name = 'Alice';
        $this->em->persist($entity);
        $this->em->flush();
        $this->buffer->flush();
        $this->em->getConnection()->executeStatement('DELETE FROM hexis_audit_log');

        $this->em->remove($entity);
        $this->em->flush();
        $this->buffer->flush();

        $row = $this->em->getConnection()->fetchAssociative(
            'SELECT * FROM hexis_audit_log WHERE event_type = ?',
            [EventType::ENTITY_DELETE->value],
        );
        self::assertNotFalse($row);
        $pre = json_decode($row['pre_image'], true);
        self::assertSame('Alice', $pre['name']);
        self::assertNull($row['post_image']);
    }

    public function testIgnoredFieldExcludedFromSnapshot(): void
    {
        $entity = new FullAuditedEntity();
        $entity->name = 'Alice';
        $entity->internalNote = 'TOP SECRET';
        $this->em->persist($entity);
        $this->em->flush();
        $this->buffer->flush();

        $row = $this->em->getConnection()->fetchAssociative(
            'SELECT * FROM hexis_audit_log WHERE event_type = ?',
            [EventType::ENTITY_INSERT->value],
        );
        self::assertNotFalse($row);
        $postImage = json_decode($row['post_image'], true);
        self::assertArrayNotHasKey('internalNote', $postImage);
        self::assertStringNotContainsString('TOP SECRET', json_encode($row));
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
