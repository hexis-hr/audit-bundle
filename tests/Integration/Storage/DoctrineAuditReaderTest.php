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
use Hexis\AuditBundle\Storage\DoctrineAuditReader;
use Hexis\AuditBundle\Storage\DoctrineAuditWriter;
use Hexis\AuditBundle\Tests\Integration\SchemaFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Ulid;

final class DoctrineAuditReaderTest extends TestCase
{
    private Connection $connection;
    private DoctrineAuditReader $reader;
    private DoctrineAuditWriter $writer;

    protected function setUp(): void
    {
        $this->connection = SchemaFactory::create();
        $this->reader = new DoctrineAuditReader($this->connection, SchemaFactory::TABLE);
        $this->writer = new DoctrineAuditWriter($this->connection, SchemaFactory::TABLE);
    }

    public function testListReturnsRowsOrderedByOccurredAtDesc(): void
    {
        $this->writer->write($this->event(EventType::LOGIN, 'alice', occurredAt: new \DateTimeImmutable('2026-04-21T09:00:00')));
        $this->writer->write($this->event(EventType::LOGIN, 'alice', occurredAt: new \DateTimeImmutable('2026-04-21T10:00:00')));
        $this->writer->write($this->event(EventType::LOGOUT, 'alice', occurredAt: new \DateTimeImmutable('2026-04-21T08:00:00')));

        $result = $this->reader->list([], 10, 0);

        self::assertSame(3, $result['total']);
        self::assertCount(3, $result['rows']);
        self::assertSame('2026-04-21T10:00:00+00:00', $result['rows'][0]['occurred_at']->format(\DateTimeInterface::ATOM));
        self::assertSame('2026-04-21T08:00:00+00:00', $result['rows'][2]['occurred_at']->format(\DateTimeInterface::ATOM));
    }

    public function testFilterByEventType(): void
    {
        $this->writer->write($this->event(EventType::LOGIN, 'alice'));
        $this->writer->write($this->event(EventType::LOGIN, 'bob'));
        $this->writer->write($this->event(EventType::LOGOUT, 'alice'));

        $result = $this->reader->list(['event_type' => EventType::LOGIN->value], 10, 0);

        self::assertSame(2, $result['total']);
    }

    public function testFilterByActor(): void
    {
        $this->writer->write($this->event(EventType::LOGIN, 'alice'));
        $this->writer->write($this->event(EventType::LOGIN, 'bob'));

        $result = $this->reader->list(['actor_id' => 'alice'], 10, 0);

        self::assertSame(1, $result['total']);
        self::assertSame('alice', $result['rows'][0]['actor_id']);
    }

    public function testFilterByDateRange(): void
    {
        $this->writer->write($this->event(EventType::LOGIN, 'alice', occurredAt: new \DateTimeImmutable('2026-01-01T00:00:00')));
        $this->writer->write($this->event(EventType::LOGIN, 'alice', occurredAt: new \DateTimeImmutable('2026-04-21T12:00:00')));
        $this->writer->write($this->event(EventType::LOGIN, 'alice', occurredAt: new \DateTimeImmutable('2026-06-01T00:00:00')));

        $result = $this->reader->list([
            'from' => new \DateTimeImmutable('2026-04-01'),
            'to' => new \DateTimeImmutable('2026-05-01'),
        ], 10, 0);

        self::assertSame(1, $result['total']);
    }

    public function testGetByEventId(): void
    {
        $event = $this->event(EventType::LOGIN, 'alice');
        $this->writer->write($event);

        $row = $this->reader->get((string) $event->eventId);

        self::assertNotNull($row);
        self::assertSame('alice', $row['actor_id']);
        self::assertSame(EventType::LOGIN->value, $row['event_type']);
    }

    public function testGetReturnsNullOnMiss(): void
    {
        self::assertNull($this->reader->get('01HXMISSINGXXXXXXXXXXXXXXX'));
    }

    public function testSessionEventsOrderedAscendingByOccurredAt(): void
    {
        $session = str_repeat('a', 64);
        $this->writer->write($this->event(EventType::LOGIN, 'alice', session: $session, occurredAt: new \DateTimeImmutable('2026-04-21T10:00:00')));
        $this->writer->write($this->event(EventType::ENTITY_UPDATE, 'alice', session: $session, occurredAt: new \DateTimeImmutable('2026-04-21T10:05:00')));
        $this->writer->write($this->event(EventType::LOGOUT, 'alice', session: $session, occurredAt: new \DateTimeImmutable('2026-04-21T10:30:00')));
        // Event in a different session — must not leak
        $this->writer->write($this->event(EventType::LOGIN, 'bob', session: str_repeat('b', 64), occurredAt: new \DateTimeImmutable('2026-04-21T10:15:00')));

        $rows = $this->reader->sessionEvents($session);

        self::assertCount(3, $rows);
        self::assertSame('login', $rows[0]['event_type']);
        self::assertSame('entity_update', $rows[1]['event_type']);
        self::assertSame('logout', $rows[2]['event_type']);
    }

    public function testDistinctEventTypes(): void
    {
        $this->writer->write($this->event(EventType::LOGIN, 'alice'));
        $this->writer->write($this->event(EventType::LOGIN, 'bob'));
        $this->writer->write($this->event(EventType::LOGOUT, 'alice'));

        $types = $this->reader->distinctEventTypes();

        self::assertContains(EventType::LOGIN->value, $types);
        self::assertContains(EventType::LOGOUT->value, $types);
        self::assertCount(2, $types);
    }

    public function testDistinctFirewalls(): void
    {
        $this->writer->write($this->event(EventType::LOGIN, 'alice', firewall: 'main'));
        $this->writer->write($this->event(EventType::LOGIN, 'ben', firewall: 'superadmin'));
        $this->writer->write($this->event(EventType::LOGOUT, 'alice', firewall: null));

        $firewalls = $this->reader->distinctFirewalls();

        self::assertContains('main', $firewalls);
        self::assertContains('superadmin', $firewalls);
        self::assertCount(2, $firewalls);
    }

    public function testPreImageAndDiffAreDecoded(): void
    {
        $event = new AuditEvent(
            type: EventType::ENTITY_UPDATE,
            actor: new Actor(id: 'alice'),
            target: Target::entity('App\\Entity\\Profile', 7),
            snapshot: Snapshot::full(['name' => 'Alice'], ['name' => 'Alicia'], ['name' => ['old' => 'Alice', 'new' => 'Alicia']]),
            context: AuditContext::empty(),
        );
        $this->writer->write($event);

        $row = $this->reader->get((string) $event->eventId);

        self::assertSame(['name' => 'Alice'], $row['pre_image']);
        self::assertSame(['name' => 'Alicia'], $row['post_image']);
        self::assertSame(['name' => ['old' => 'Alice', 'new' => 'Alicia']], $row['diff']);
    }

    private function event(
        EventType $type,
        string $actorId,
        ?string $firewall = 'main',
        ?string $session = null,
        ?\DateTimeImmutable $occurredAt = null,
    ): AuditEvent {
        return new AuditEvent(
            type: $type,
            actor: new Actor(id: $actorId, firewall: $firewall),
            target: new Target(),
            snapshot: Snapshot::none(),
            context: new AuditContext(sessionIdHash: $session),
            eventId: new Ulid(),
            occurredAt: $occurredAt,
        );
    }
}
