<?php

declare(strict_types=1);

namespace Hexis\AuditBundle\Tests\Unit\Storage;

use Hexis\AuditBundle\Storage\Elasticsearch\ElasticsearchClient;
use Hexis\AuditBundle\Storage\ElasticsearchAuditReader;
use PHPUnit\Framework\TestCase;

final class ElasticsearchAuditReaderTest extends TestCase
{
    public function testListBuildsRangeAndTermsQuery(): void
    {
        $client = new CapturingSearchClient();
        $reader = new ElasticsearchAuditReader($client, indexPattern: 'audit-*');

        $reader->list([
            'event_type' => 'login',
            'actor_id' => 'alice',
            'from' => new \DateTimeImmutable('2026-04-01T00:00:00+00:00'),
            'to' => new \DateTimeImmutable('2026-04-30T23:59:59+00:00'),
            'search' => 'example',
        ], 50, 0);

        self::assertCount(1, $client->searchCalls);
        $body = $client->searchCalls[0]['body'];
        self::assertSame('audit-*', $client->searchCalls[0]['index']);
        self::assertArrayHasKey('query', $body);
        self::assertArrayHasKey('bool', $body['query']);

        $must = $body['query']['bool']['must'];
        self::assertContains(['term' => ['event_type' => 'login']], $must);
        self::assertContains(['term' => ['actor.id' => 'alice']], $must);
    }

    public function testMatchAllWhenNoFilters(): void
    {
        $client = new CapturingSearchClient();
        $reader = new ElasticsearchAuditReader($client);

        $reader->list([], 25, 0);

        $body = $client->searchCalls[0]['body'];
        self::assertArrayHasKey('match_all', $body['query']);
    }

    public function testGetReturnsFirstHit(): void
    {
        $client = new CapturingSearchClient();
        $client->response = [
            'hits' => [
                ['_id' => '01HXABC', '_source' => [
                    'event_id' => '01HXABC',
                    'event_type' => 'login',
                    'occurred_at' => '2026-04-21T10:00:00+00:00',
                    'actor' => ['id' => 'alice'],
                ]],
            ],
            'total' => 1,
        ];
        $reader = new ElasticsearchAuditReader($client);

        $row = $reader->get('01HXABC');

        self::assertNotNull($row);
        self::assertSame('01HXABC', $row['event_id']);
        self::assertSame('alice', $row['actor_id']);
        self::assertInstanceOf(\DateTimeImmutable::class, $row['occurred_at']);
    }

    public function testGetReturnsNullOnMiss(): void
    {
        $client = new CapturingSearchClient();
        $client->response = ['hits' => [], 'total' => 0];
        $reader = new ElasticsearchAuditReader($client);

        self::assertNull($reader->get('01HXMISSING'));
    }

    public function testSessionEventsOrderedAsc(): void
    {
        $client = new CapturingSearchClient();
        $reader = new ElasticsearchAuditReader($client);

        $reader->sessionEvents(str_repeat('a', 64));

        $body = $client->searchCalls[0]['body'];
        self::assertSame(
            [['occurred_at' => ['order' => 'asc']]],
            $body['sort'],
        );
        self::assertSame(['term' => ['session_id_hash' => str_repeat('a', 64)]], $body['query']);
    }

    public function testHydrationFlattensNestedActorTargetRequest(): void
    {
        $client = new CapturingSearchClient();
        $client->response = [
            'hits' => [
                ['_source' => [
                    'event_id' => '01HXABC',
                    'event_type' => 'entity_update',
                    'occurred_at' => '2026-04-21T10:00:00+00:00',
                    'actor' => ['id' => 'alice', 'firewall' => 'main'],
                    'target' => ['class' => 'App\\Entity\\Foo', 'id' => '42'],
                    'request' => ['method' => 'POST', 'path' => '/foo/42'],
                    'snapshot' => ['mode' => 'changed_fields', 'diff' => ['name' => ['old' => 'A', 'new' => 'B']]],
                ]],
            ],
            'total' => 1,
        ];
        $reader = new ElasticsearchAuditReader($client);

        $row = $reader->get('01HXABC');

        self::assertSame('alice', $row['actor_id']);
        self::assertSame('main', $row['actor_firewall']);
        self::assertSame('App\\Entity\\Foo', $row['target_class']);
        self::assertSame('42', $row['target_id']);
        self::assertSame('POST', $row['request_method']);
        self::assertSame('/foo/42', $row['request_path']);
        self::assertSame('changed_fields', $row['snapshot_mode']);
        self::assertSame(['name' => ['old' => 'A', 'new' => 'B']], $row['diff']);
    }
}

final class CapturingSearchClient implements ElasticsearchClient
{
    /** @var list<array{index: string, body: array}> */
    public array $searchCalls = [];
    /** @var array{hits: list<array>, total: int} */
    public array $response = ['hits' => [], 'total' => 0];

    public function index(string $index, string $id, array $document, bool $refresh = false): void
    {
    }
    public function bulk(iterable $operations, bool $refresh = false): void
    {
    }
    public function putIndexTemplate(string $name, array $template): void
    {
    }

    public function search(string $indexPattern, array $body): array
    {
        $this->searchCalls[] = ['index' => $indexPattern, 'body' => $body];
        return $this->response;
    }
}
