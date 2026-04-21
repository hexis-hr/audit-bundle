<?php

declare(strict_types=1);

namespace Hexis\AuditBundle\Tests\Unit\Legacy;

use Hexis\AuditBundle\Domain\EventType;
use Hexis\AuditBundle\Domain\Snapshot;
use Hexis\AuditBundle\Legacy\DefaultLegacyRowMapper;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Ulid;

final class DefaultLegacyRowMapperTest extends TestCase
{
    public function testMapsKnownActionStrings(): void
    {
        $mapper = new DefaultLegacyRowMapper();

        self::assertSame(EventType::LOGIN, $mapper->map(['action' => 'login', 'created_at' => '2026-01-01'])->type);
        self::assertSame(EventType::LOGOUT, $mapper->map(['action' => 'logged_out', 'created_at' => '2026-01-01'])->type);
        self::assertSame(EventType::ENTITY_UPDATE, $mapper->map(['action' => 'updated', 'created_at' => '2026-01-01'])->type);
    }

    public function testUnknownActionBecomesCustomAndPreservesOriginal(): void
    {
        $mapper = new DefaultLegacyRowMapper();

        $event = $mapper->map(['action' => 'payroll_approved', 'created_at' => '2026-01-01']);

        self::assertSame(EventType::CUSTOM, $event->type);
        self::assertSame('payroll_approved', $event->action);
    }

    public function testReusesUlidFromLegacyIdWhenValid(): void
    {
        $mapper = new DefaultLegacyRowMapper();
        $existing = new Ulid();

        $event = $mapper->map([
            'id' => (string) $existing,
            'action' => 'login',
            'created_at' => '2026-01-01',
        ]);

        self::assertSame((string) $existing, (string) $event->eventId);
    }

    public function testGeneratesNewUlidWhenLegacyIdIsNotUlid(): void
    {
        $mapper = new DefaultLegacyRowMapper();

        $event = $mapper->map([
            'id' => '12345',
            'action' => 'login',
            'created_at' => '2026-01-01',
        ]);

        // New ULID generated; just check it's valid
        Ulid::fromString((string) $event->eventId);
        $this->addToAssertionCount(1);
    }

    public function testNormalizedDiff(): void
    {
        $mapper = new DefaultLegacyRowMapper();

        $event = $mapper->map([
            'action' => 'update',
            'changes' => json_encode(['name' => ['old' => 'Alice', 'new' => 'Alicia']]),
            'created_at' => '2026-01-01',
        ]);

        self::assertSame(Snapshot::MODE_CHANGED_FIELDS, $event->snapshot->mode);
        self::assertSame(['old' => 'Alice', 'new' => 'Alicia'], $event->snapshot->diff['name']);
    }

    public function testChangesWithoutOldNewTreatedAsNewOnly(): void
    {
        $mapper = new DefaultLegacyRowMapper();

        $event = $mapper->map([
            'action' => 'update',
            'changes' => ['name' => 'Alice'],
            'created_at' => '2026-01-01',
        ]);

        self::assertSame(['old' => null, 'new' => 'Alice'], $event->snapshot->diff['name']);
    }

    public function testContextFields(): void
    {
        $mapper = new DefaultLegacyRowMapper();

        $event = $mapper->map([
            'action' => 'login',
            'ip_address' => '10.0.0.1',
            'user_agent' => 'curl/7.68',
            'method' => 'POST',
            'path' => '/login',
            'created_at' => '2026-01-01',
        ]);

        self::assertSame('10.0.0.1', $event->context->ipAddress);
        self::assertSame('curl/7.68', $event->context->userAgent);
        self::assertSame('POST', $event->context->requestMethod);
        self::assertSame('/login', $event->context->requestPath);
    }
}
