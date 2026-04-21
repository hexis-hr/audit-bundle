<?php

declare(strict_types=1);

namespace Hexis\AuditBundle\Storage;

use Hexis\AuditBundle\Storage\Elasticsearch\ElasticsearchClient;

/**
 * Reads from one or more Elasticsearch indices matching the configured pattern.
 *
 * For list+aggregation we target the whole pattern (e.g. `audit-*`) so a single query
 * spans all rolled-over monthly indices. The document shape is identical to what
 * ElasticsearchAuditWriter produces — this reader inverts that shape into the common
 * AuditReader row format.
 */
final class ElasticsearchAuditReader implements AuditReader
{
    public function __construct(
        private readonly ElasticsearchClient $client,
        private readonly string $indexPattern = 'audit-*',
    ) {
    }

    public function list(array $filters, int $limit, int $offset): array
    {
        $body = [
            'query' => $this->buildQuery($filters),
            'sort' => [['occurred_at' => ['order' => 'desc']]],
            'from' => max(0, $offset),
            'size' => max(1, $limit),
            'track_total_hits' => true,
        ];

        $result = $this->client->search($this->indexPattern, $body);

        return [
            'rows' => array_map([$this, 'hydrate'], $result['hits']),
            'total' => $result['total'],
        ];
    }

    public function get(string $eventId): ?array
    {
        $body = [
            'query' => ['term' => ['event_id' => $eventId]],
            'size' => 1,
        ];

        $result = $this->client->search($this->indexPattern, $body);

        if ($result['hits'] === []) {
            return null;
        }

        return $this->hydrate($result['hits'][0]);
    }

    public function sessionEvents(string $sessionIdHash, int $limit = 500): array
    {
        $body = [
            'query' => ['term' => ['session_id_hash' => $sessionIdHash]],
            'sort' => [['occurred_at' => ['order' => 'asc']]],
            'size' => max(1, $limit),
            'track_total_hits' => false,
        ];

        $result = $this->client->search($this->indexPattern, $body);

        return array_map([$this, 'hydrate'], $result['hits']);
    }

    public function distinctEventTypes(): array
    {
        return $this->distinctTerms('event_type');
    }

    public function distinctFirewalls(): array
    {
        return $this->distinctTerms('actor.firewall');
    }

    public function distinctSources(): array
    {
        return $this->distinctTerms('source');
    }

    /**
     * @return list<string>
     */
    private function distinctTerms(string $field): array
    {
        $body = [
            'size' => 0,
            'aggs' => [
                'distinct' => ['terms' => ['field' => $field, 'size' => 100]],
            ],
        ];

        $result = $this->client->search($this->indexPattern, $body);

        $out = [];
        foreach ($result['hits'] as $hit) {
            // aggregations typically ride alongside hits in ES response; the concrete adapter
            // may surface them via `_aggregations` or similar. Fallback to empty if the
            // adapter didn't forward aggs (adapters can upgrade incrementally).
            $aggs = $hit['_aggregations'] ?? $hit['aggregations'] ?? null;
            if ($aggs !== null && isset($aggs['distinct']['buckets'])) {
                foreach ($aggs['distinct']['buckets'] as $bucket) {
                    $out[] = (string) $bucket['key'];
                }
                break;
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private function buildQuery(array $filters): array
    {
        $must = [];

        $termFields = [
            'event_type' => 'event_type',
            'source' => 'source',
            'actor_id' => 'actor.id',
            'actor_firewall' => 'actor.firewall',
            'target_class' => 'target.class',
            'target_id' => 'target.id',
            'session_id_hash' => 'session_id_hash',
        ];

        foreach ($termFields as $filterKey => $field) {
            if (($filters[$filterKey] ?? null) !== null && $filters[$filterKey] !== '') {
                $must[] = ['term' => [$field => $filters[$filterKey]]];
            }
        }

        $range = [];
        if (!empty($filters['from']) && $filters['from'] instanceof \DateTimeImmutable) {
            $range['gte'] = $filters['from']->format(\DateTimeInterface::ATOM);
        }
        if (!empty($filters['to']) && $filters['to'] instanceof \DateTimeImmutable) {
            $range['lte'] = $filters['to']->format(\DateTimeInterface::ATOM);
        }
        if ($range !== []) {
            $must[] = ['range' => ['occurred_at' => $range]];
        }

        if (!empty($filters['search']) && \is_string($filters['search'])) {
            $must[] = [
                'multi_match' => [
                    'query' => $filters['search'],
                    'fields' => ['actor.id', 'target.class', 'target.id', 'action'],
                ],
            ];
        }

        if ($must === []) {
            return ['match_all' => new \stdClass()];
        }

        return ['bool' => ['must' => $must]];
    }

    /**
     * @param array{_id?: string, _source?: array<string, mixed>} $hit
     * @return array<string, mixed>
     */
    private function hydrate(array $hit): array
    {
        $source = $hit['_source'] ?? [];

        return [
            'event_id' => $source['event_id'] ?? ($hit['_id'] ?? null),
            'event_type' => $source['event_type'] ?? null,
            'source' => $source['source'] ?? null,
            'occurred_at' => isset($source['occurred_at']) ? new \DateTimeImmutable($source['occurred_at']) : null,
            'actor_id' => $source['actor']['id'] ?? null,
            'actor_type' => $source['actor']['type'] ?? null,
            'actor_firewall' => $source['actor']['firewall'] ?? null,
            'impersonator_id' => $source['impersonator_id'] ?? null,
            'target_class' => $source['target']['class'] ?? null,
            'target_id' => $source['target']['id'] ?? null,
            'action' => $source['action'] ?? null,
            'ip_address' => $source['ip_address'] ?? null,
            'user_agent' => $source['user_agent'] ?? null,
            'request_method' => $source['request']['method'] ?? null,
            'request_path' => $source['request']['path'] ?? null,
            'session_id_hash' => $source['session_id_hash'] ?? null,
            'snapshot_mode' => $source['snapshot']['mode'] ?? null,
            'pre_image' => $source['snapshot']['pre_image'] ?? null,
            'post_image' => $source['snapshot']['post_image'] ?? null,
            'diff' => $source['snapshot']['diff'] ?? null,
            'context' => $source['context'] ?? null,
            'source_of_truth' => 'primary',
        ];
    }
}
