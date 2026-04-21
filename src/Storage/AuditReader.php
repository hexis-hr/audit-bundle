<?php

declare(strict_types=1);

namespace Hexis\AuditBundle\Storage;

/**
 * Storage-agnostic read API. Implementations exist per backend (Doctrine, Elasticsearch).
 *
 * Row shape (returned as an associative array, not an object — avoids coupling to any backend's
 * native row type):
 *   event_id:        string (ULID)
 *   event_type:      string (matches EventType->value)
 *   source:          ?string
 *   occurred_at:     \DateTimeImmutable
 *   actor_id:        ?string
 *   actor_type:      ?string
 *   actor_firewall:  ?string
 *   impersonator_id: ?string
 *   target_class:    ?string
 *   target_id:       ?string
 *   action:          ?string
 *   ip_address:      ?string
 *   user_agent:      ?string
 *   request_method:  ?string
 *   request_path:    ?string
 *   session_id_hash: ?string
 *   snapshot_mode:   ?string
 *   pre_image:       ?array
 *   post_image:      ?array
 *   diff:            ?array
 *   context:         ?array
 *   source_of_truth: ?string
 */
interface AuditReader
{
    /**
     * @param array{
     *     event_type?: ?string,
     *     source?: ?string,
     *     actor_id?: ?string,
     *     actor_firewall?: ?string,
     *     target_class?: ?string,
     *     target_id?: ?string,
     *     session_id_hash?: ?string,
     *     from?: ?\DateTimeImmutable,
     *     to?: ?\DateTimeImmutable,
     *     search?: ?string,
     * } $filters
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function list(array $filters, int $limit, int $offset): array;

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $eventId): ?array;

    /**
     * Events that share a session_id_hash, ordered by occurred_at ASC. Used for timeline / workflow views.
     *
     * @return list<array<string, mixed>>
     */
    public function sessionEvents(string $sessionIdHash, int $limit = 500): array;

    /** @return list<string> */
    public function distinctEventTypes(): array;

    /** @return list<string> */
    public function distinctFirewalls(): array;

    /** @return list<string> */
    public function distinctSources(): array;
}
