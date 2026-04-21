<?php

declare(strict_types=1);

namespace Hexis\AuditBundle\Tests\Integration\Storage;

use Doctrine\DBAL\Connection;
use Hexis\AuditBundle\Domain\Actor;
use Hexis\AuditBundle\Domain\AuditContext;
use Hexis\AuditBundle\Domain\AuditEvent;
use Hexis\AuditBundle\Domain\EventType;
use Hexis\AuditBundle\Domain\Snapshot;
use Hexis\AuditBundle\Domain\Target;
use Hexis\AuditBundle\Storage\DoctrineAuditWriter;
use Hexis\AuditBundle\Tests\Integration\SchemaFactory;
use PHPUnit\Framework\TestCase;

final class DoctrineAuditWriterTest extends TestCase
{
    private Connection $connection;
    private DoctrineAuditWriter $writer;

    protected function setUp(): void
    {
        $this->connection = SchemaFactory::create();
        $this->writer = new DoctrineAuditWriter(
            connection: $this->connection,
            table: SchemaFactory::TABLE,
        );
    }

    public function testWritesSingleEvent(): void
    {
        $event = $this->buildLoginEvent();

        $this->writer->write($event);

        $row = $this->connection->fetchAssociative('SELECT * FROM hexis_audit_log');
        self::assertNotFalse($row);
        self::assertSame($event->eventId->toBase32(), $row['event_id']);
        self::assertSame('login', $row['event_type']);
        self::assertSame('user-42', $row['actor_id']);
        self::assertSame('main', $row['actor_firewall']);
        self::assertSame('primary', $row['source_of_truth']);
        self::assertNull($row['pending_replay_at']);
    }

    public function testWritesBatch(): void
    {
        $events = [
            $this->buildLoginEvent(),
            $this->buildLogoutEvent(),
            $this->buildEntityUpdateEvent(),
        ];

        $this->writer->writeBatch($events);

        self::assertSame(3, $this->rowCount());
    }

    public function testBatchIsAtomic(): void
    {
        $duplicate = $this->buildLoginEvent();
        // same eventId on both — second insert violates UNIQUE constraint, whole batch rolls back
        $events = [$duplicate, $duplicate];

        try {
            $this->writer->writeBatch($events);
            self::fail('Expected write to throw on unique constraint violation');
        } catch (\Throwable) {
            // expected
        }

        self::assertSame(0, $this->rowCount(), 'Batch should have rolled back — no partial writes');
    }

    public function testFallbackWriterMarksPendingReplay(): void
    {
        $fallbackWriter = new DoctrineAuditWriter(
            connection: $this->connection,
            table: SchemaFactory::TABLE,
            sourceOfTruth: DoctrineAuditWriter::SOURCE_FALLBACK,
        );

        $event = $this->buildLoginEvent();
        $fallbackWriter->write($event);

        $row = $this->connection->fetchAssociative('SELECT * FROM hexis_audit_log');
        self::assertNotFalse($row);
        self::assertSame('fallback', $row['source_of_truth']);
        self::assertNotNull($row['pending_replay_at']);
    }

    public function testSessionIdHashIsStored(): void
    {
        $hash = hash('sha256', 'raw-session-value');
        $event = new AuditEvent(
            type: EventType::LOGIN,
            actor: new Actor(id: 'user-42', firewall: 'main'),
            target: new Target(),
            snapshot: Snapshot::none(),
            context: new AuditContext(sessionIdHash: $hash),
        );

        $this->writer->write($event);

        $row = $this->connection->fetchAssociative('SELECT * FROM hexis_audit_log');
        self::assertNotFalse($row);
        self::assertSame($hash, $row['session_id_hash']);
    }

    public function testDiffIsJsonEncoded(): void
    {
        $diff = ['email' => ['old' => 'a@example.com', 'new' => 'b@example.com']];
        $event = new AuditEvent(
            type: EventType::ENTITY_UPDATE,
            actor: new Actor(id: 'user-42'),
            target: Target::entity('App\\Entity\\Profile', 7),
            snapshot: Snapshot::changedFields($diff),
            context: AuditContext::empty(),
        );

        $this->writer->write($event);

        $row = $this->connection->fetchAssociative('SELECT * FROM hexis_audit_log');
        self::assertNotFalse($row);
        self::assertSame('changed_fields', $row['snapshot_mode']);
        self::assertSame($diff, json_decode($row['diff'], true));
    }

    private function buildLoginEvent(): AuditEvent
    {
        return new AuditEvent(
            type: EventType::LOGIN,
            actor: new Actor(id: 'user-42', type: 'App\\Entity\\User', firewall: 'main'),
            target: new Target(),
            snapshot: Snapshot::none(),
            context: new AuditContext(
                ipAddress: '127.0.0.1',
                userAgent: 'phpunit',
                requestMethod: 'POST',
                requestPath: '/login',
                sessionIdHash: str_repeat('a', 64),
            ),
        );
    }

    private function buildLogoutEvent(): AuditEvent
    {
        return new AuditEvent(
            type: EventType::LOGOUT,
            actor: new Actor(id: 'user-42', firewall: 'main'),
            target: new Target(),
            snapshot: Snapshot::none(),
            context: AuditContext::empty(),
        );
    }

    private function buildEntityUpdateEvent(): AuditEvent
    {
        return new AuditEvent(
            type: EventType::ENTITY_UPDATE,
            actor: new Actor(id: 'user-42'),
            target: Target::entity('App\\Entity\\Profile', 7),
            snapshot: Snapshot::changedFields(['name' => ['old' => 'Alice', 'new' => 'Alicia']]),
            context: AuditContext::empty(),
        );
    }

    private function rowCount(): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM hexis_audit_log');
    }
}
