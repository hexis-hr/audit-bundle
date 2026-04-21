<?php

declare(strict_types=1);

namespace Hexis\AuditBundle\Storage;

use Hexis\AuditBundle\Domain\AuditEvent;
use Hexis\AuditBundle\Storage\Elasticsearch\ElasticsearchClient;

/**
 * Writes audit events to Elasticsearch. Uses the ULID event_id as the document _id so replays
 * from the fallback writer are idempotent — Elasticsearch will overwrite (not duplicate).
 *
 * Index name is resolved from a strftime-style pattern against the event's `occurred_at` timestamp,
 * so rolling indices like `audit-%Y.%m` yield `audit-2026.04` for events in April 2026.
 */
final class ElasticsearchAuditWriter implements AuditWriter
{
    public function __construct(
        private readonly ElasticsearchClient $client,
        private readonly string $indexPattern = 'audit-%Y.%m',
        private readonly bool $refresh = false,
    ) {
    }

    public function write(AuditEvent $event): void
    {
        $index = $this->resolveIndex($event->occurredAt);
        $this->client->index(
            index: $index,
            id: (string) $event->eventId,
            document: $this->toDocument($event),
            refresh: $this->refresh,
        );
    }

    public function writeBatch(iterable $events): void
    {
        $operations = [];
        foreach ($events as $event) {
            $operations[] = [
                $this->resolveIndex($event->occurredAt),
                (string) $event->eventId,
                $this->toDocument($event),
            ];
        }

        if ($operations === []) {
            return;
        }

        $this->client->bulk($operations, $this->refresh);
    }

    private function resolveIndex(\DateTimeImmutable $at): string
    {
        // Strftime-style tokens commonly used for index rotation. We don't shell out to strftime()
        // since it's deprecated in PHP 8.1+.
        return strtr($this->indexPattern, [
            '%Y' => $at->format('Y'),
            '%m' => $at->format('m'),
            '%d' => $at->format('d'),
            '%H' => $at->format('H'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function toDocument(AuditEvent $event): array
    {
        return [
            'event_id' => (string) $event->eventId,
            'event_type' => $event->type->value,
            'source' => $event->source,
            'occurred_at' => $event->occurredAt->format(\DateTimeInterface::ATOM),
            'actor' => [
                'id' => $event->actor->id,
                'type' => $event->actor->type,
                'firewall' => $event->actor->firewall,
            ],
            'impersonator_id' => $event->actor->impersonatorId,
            'target' => [
                'class' => $event->target->class,
                'id' => $event->target->id,
                'label' => $event->target->label,
            ],
            'action' => $event->action,
            'ip_address' => $event->context->ipAddress,
            'user_agent' => $event->context->userAgent,
            'request' => [
                'method' => $event->context->requestMethod,
                'path' => $event->context->requestPath,
            ],
            'session_id_hash' => $event->context->sessionIdHash,
            'snapshot' => [
                'mode' => $event->snapshot->mode,
                'pre_image' => $event->snapshot->preImage,
                'post_image' => $event->snapshot->postImage,
                'diff' => $event->snapshot->diff,
            ],
            'context' => $event->context->extra === [] ? null : $event->context->extra,
        ];
    }
}
