<?php

declare(strict_types=1);

namespace Hexis\AuditBundle\Legacy;

use Hexis\AuditBundle\Domain\Actor;
use Hexis\AuditBundle\Domain\AuditContext;
use Hexis\AuditBundle\Domain\AuditEvent;
use Hexis\AuditBundle\Domain\EventType;
use Hexis\AuditBundle\Domain\Snapshot;
use Hexis\AuditBundle\Domain\Target;
use Symfony\Component\Uid\Ulid;

/**
 * Default mapper for the ad-hoc `audit_log` schema commonly found in Symfony apps:
 *
 *   id (ULID or int), user_id, action (string), entity_class, entity_id, changes (JSON),
 *   ip_address, user_agent, method, path, created_at.
 *
 * Action strings are matched loosely to EventType; unknowns become CUSTOM with the original
 * action preserved in the `action` field of the new row.
 */
final class DefaultLegacyRowMapper implements LegacyRowMapper
{
    private const ACTION_MAP = [
        'login' => EventType::LOGIN,
        'logged_in' => EventType::LOGIN,
        'logout' => EventType::LOGOUT,
        'logged_out' => EventType::LOGOUT,
        'login_failed' => EventType::LOGIN_FAILURE,
        'login_failure' => EventType::LOGIN_FAILURE,
        'switch_user' => EventType::SWITCH_USER_ENTER,
        'impersonate' => EventType::SWITCH_USER_ENTER,
        'impersonate_exit' => EventType::SWITCH_USER_EXIT,
        'insert' => EventType::ENTITY_INSERT,
        'create' => EventType::ENTITY_INSERT,
        'created' => EventType::ENTITY_INSERT,
        'update' => EventType::ENTITY_UPDATE,
        'updated' => EventType::ENTITY_UPDATE,
        'delete' => EventType::ENTITY_DELETE,
        'deleted' => EventType::ENTITY_DELETE,
    ];

    public function map(array $row): ?AuditEvent
    {
        $action = isset($row['action']) ? (string) $row['action'] : '';
        $type = self::ACTION_MAP[strtolower($action)] ?? EventType::CUSTOM;

        $diff = null;
        $snapshotMode = Snapshot::MODE_NONE;
        if (isset($row['changes']) && $row['changes'] !== null && $row['changes'] !== '') {
            $decoded = is_array($row['changes']) ? $row['changes'] : json_decode((string) $row['changes'], true);
            if (is_array($decoded) && $decoded !== []) {
                $diff = $this->normalizeDiff($decoded);
                $snapshotMode = Snapshot::MODE_CHANGED_FIELDS;
            }
        }

        $eventId = $this->resolveEventId($row['id'] ?? null);

        $occurredAt = isset($row['created_at'])
            ? new \DateTimeImmutable((string) $row['created_at'])
            : new \DateTimeImmutable();

        return new AuditEvent(
            type: $type,
            actor: new Actor(
                id: isset($row['user_id']) ? (string) $row['user_id'] : null,
            ),
            target: new Target(
                class: isset($row['entity_class']) ? (string) $row['entity_class'] : null,
                id: isset($row['entity_id']) ? (string) $row['entity_id'] : null,
            ),
            snapshot: new Snapshot(
                mode: $snapshotMode,
                diff: $diff,
            ),
            context: new AuditContext(
                ipAddress: isset($row['ip_address']) ? (string) $row['ip_address'] : null,
                userAgent: isset($row['user_agent']) ? (string) $row['user_agent'] : null,
                requestMethod: isset($row['method']) ? (string) $row['method'] : null,
                requestPath: isset($row['path']) ? (string) $row['path'] : null,
            ),
            action: $type === EventType::CUSTOM ? $action : null,
            source: 'legacy',
            eventId: $eventId,
            occurredAt: $occurredAt,
        );
    }

    /**
     * @return array<string, array{old: mixed, new: mixed}>
     */
    private function normalizeDiff(array $changes): array
    {
        $out = [];
        foreach ($changes as $field => $value) {
            if (is_array($value) && \array_key_exists('old', $value) && \array_key_exists('new', $value)) {
                $out[(string) $field] = ['old' => $value['old'], 'new' => $value['new']];
                continue;
            }
            // Fallback: treat the value as the "new" state, old unknown.
            $out[(string) $field] = ['old' => null, 'new' => $value];
        }

        return $out;
    }

    private function resolveEventId(mixed $legacyId): Ulid
    {
        if ($legacyId === null) {
            return new Ulid();
        }

        $legacyId = (string) $legacyId;
        // Try to reuse if it's already a ULID — keeps dedupe idempotent across re-runs.
        try {
            return Ulid::fromString($legacyId);
        } catch (\Throwable) {
            return new Ulid();
        }
    }
}
