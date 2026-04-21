<?php

declare(strict_types=1);

namespace Hexis\AuditBundle\Storage\Elasticsearch;

/**
 * Thin seam between the bundle and any Elasticsearch client implementation.
 *
 * Hosts using `elastic/elasticsearch` (8.x) provide an adapter via the `audit.storage.elasticsearch.client`
 * service id. This interface keeps the bundle decoupled from a specific client version so the same
 * writer works whether the host uses `elastic/elasticsearch`, `ruflin/elastica`, or a custom HTTP client.
 */
interface ElasticsearchClient
{
    /**
     * Index a single document. Should be idempotent by $id so fallback replay doesn't create duplicates.
     *
     * @param array<string, mixed> $document
     */
    public function index(string $index, string $id, array $document, bool $refresh = false): void;

    /**
     * Bulk index. Each entry is [$index, $id, $document].
     *
     * @param iterable<array{0:string, 1:string, 2:array<string, mixed>}> $operations
     */
    public function bulk(iterable $operations, bool $refresh = false): void;

    /**
     * Install or update an index template.
     *
     * @param array<string, mixed> $template
     */
    public function putIndexTemplate(string $name, array $template): void;

    /**
     * Execute a search request against one or more indices.
     *
     * @param array<string, mixed> $body   Elasticsearch query DSL body (query, sort, size, from, aggs, …).
     * @return array{hits: list<array<string, mixed>>, total: int}  hits[].['_id'|'_source'] + total matching docs.
     */
    public function search(string $indexPattern, array $body): array;
}
