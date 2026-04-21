<?php

declare(strict_types=1);

namespace Hexis\AuditBundle\Storage;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Hexis\AuditBundle\Domain\AuditEvent;

/**
 * Direct DBAL writer. Uses its own connection reference so it does NOT entangle with the
 * host's EntityManager unit-of-work — writing audit rows cannot re-enter a DoctrineAuditListener
 * and cannot roll back a host transaction.
 */
final class DoctrineAuditWriter implements AuditWriter
{
    public const SOURCE_PRIMARY = 'primary';
    public const SOURCE_FALLBACK = 'fallback';
    public const SOURCE_REPLAYED = 'replayed';

    public function __construct(
        private readonly Connection $connection,
        private readonly string $table,
        private readonly string $sourceOfTruth = self::SOURCE_PRIMARY,
    ) {
    }

    public function write(AuditEvent $event): void
    {
        $this->writeBatch([$event]);
    }

    public function writeBatch(iterable $events): void
    {
        $rows = [];
        foreach ($events as $event) {
            $rows[] = $event;
        }

        if ($rows === []) {
            return;
        }

        $this->connection->beginTransaction();
        try {
            foreach ($rows as $event) {
                $this->insertOne($event);
            }
            $this->connection->commit();
        } catch (\Throwable $e) {
            $this->connection->rollBack();
            throw $e;
        }
    }

    private function insertOne(AuditEvent $event): void
    {
        $pendingReplayAt = $this->sourceOfTruth === self::SOURCE_FALLBACK
            ? $event->occurredAt->format('Y-m-d H:i:s.u')
            : null;

        $this->connection->executeStatement(
            sprintf(
                'INSERT INTO %s (
                    event_id, event_type, source, occurred_at,
                    actor_id, actor_type, actor_firewall, impersonator_id,
                    target_class, target_id, action,
                    ip_address, user_agent, request_method, request_path, session_id_hash,
                    snapshot_mode, pre_image, post_image, diff,
                    context, source_of_truth, pending_replay_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                $this->table,
            ),
            [
                (string) $event->eventId,
                $event->type->value,
                $event->source,
                $event->occurredAt->format('Y-m-d H:i:s.u'),
                $event->actor->id,
                $event->actor->type,
                $event->actor->firewall,
                $event->actor->impersonatorId,
                $event->target->class,
                $event->target->id,
                $event->action,
                $event->context->ipAddress,
                $event->context->userAgent,
                $event->context->requestMethod,
                $event->context->requestPath,
                $event->context->sessionIdHash,
                $event->snapshot->mode,
                $this->encodeJson($event->snapshot->preImage),
                $this->encodeJson($event->snapshot->postImage),
                $this->encodeJson($event->snapshot->diff),
                $this->encodeJson($event->context->extra === [] ? null : $event->context->extra),
                $this->sourceOfTruth,
                $pendingReplayAt,
            ],
            array_fill(0, 23, ParameterType::STRING),
        );
    }

    private function encodeJson(?array $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }
}
