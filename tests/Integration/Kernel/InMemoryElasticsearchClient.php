<?php

declare(strict_types=1);

namespace Hexis\AuditBundle\Tests\Integration\Kernel;

use Hexis\AuditBundle\Storage\Elasticsearch\ElasticsearchClient;

/**
 * Stands in for the host's Elasticsearch adapter. Set $failing to simulate an outage.
 */
final class InMemoryElasticsearchClient implements ElasticsearchClient
{
    public bool $failing = false;

    /** @var array<string, array<string, mixed>> documents keyed by id */
    public array $documents = [];

    /** @var array<string, array<string, mixed>> templates keyed by name */
    public array $templates = [];

    public function index(string $index, string $id, array $document, bool $refresh = false): void
    {
        $this->failIfDown();
        $this->documents[$id] = $document;
    }

    public function bulk(iterable $operations, bool $refresh = false): void
    {
        $this->failIfDown();
        foreach ($operations as [$index, $id, $document]) {
            $this->documents[$id] = $document;
        }
    }

    public function putIndexTemplate(string $name, array $template): void
    {
        $this->failIfDown();
        $this->templates[$name] = $template;
    }

    public function search(string $indexPattern, array $body): array
    {
        return ['hits' => [], 'total' => 0];
    }

    private function failIfDown(): void
    {
        if ($this->failing) {
            throw new \RuntimeException('elasticsearch down');
        }
    }
}
