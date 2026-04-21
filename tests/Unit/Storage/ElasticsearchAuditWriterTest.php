<?php

declare(strict_types=1);

namespace Hexis\AuditBundle\Tests\Unit\Storage;

use Hexis\AuditBundle\Domain\Actor;
use Hexis\AuditBundle\Domain\AuditContext;
use Hexis\AuditBundle\Domain\AuditEvent;
use Hexis\AuditBundle\Domain\EventType;
use Hexis\AuditBundle\Domain\Snapshot;
use Hexis\AuditBundle\Domain\Target;
use Hexis\AuditBundle\Storage\Elasticsearch\ElasticsearchClient;
use Hexis\AuditBundle\Storage\ElasticsearchAuditWriter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Ulid;

final class ElasticsearchAuditWriterTest extends TestCase
{
    public function testWriteIndexesWithEventIdAsDocumentId(): void
    {
        $client = new RecordingClient();
        $writer = new ElasticsearchAuditWriter($client, indexPattern: 'audit-%Y.%m');

        $eventId = new Ulid();
        $event = new AuditEvent(
            type: EventType::LOGIN,
            actor: new Actor(id: 'user-42', firewall: 'main'),
            target: new Target(),
            snapshot: Snapshot::none(),
            context: AuditContext::empty(),
            eventId: $eventId,
            occurredAt: new \DateTimeImmutable('2026-04-21T10:00:00+00:00'),
        );

        $writer->write($event);

        self::assertCount(1, $client->indexCalls);
        self::assertSame('audit-2026.04', $client->indexCalls[0]['index']);
        self::assertSame((string) $eventId, $client->indexCalls[0]['id']);
        self::assertSame('user-42', $client->indexCalls[0]['document']['actor']['id']);
    }

    public function testBatchUsesBulk(): void
    {
        $client = new RecordingClient();
        $writer = new ElasticsearchAuditWriter($client);

        $events = [
            $this->event('2026-01-10T00:00:00+00:00'),
            $this->event('2026-04-21T00:00:00+00:00'),
        ];

        $writer->writeBatch($events);

        self::assertCount(0, $client->indexCalls);
        self::assertCount(1, $client->bulkCalls);
        self::assertCount(2, $client->bulkCalls[0]['operations']);
        // Different months should yield different indices.
        self::assertSame('audit-2026.01', $client->bulkCalls[0]['operations'][0][0]);
        self::assertSame('audit-2026.04', $client->bulkCalls[0]['operations'][1][0]);
    }

    public function testEmptyBatchIsNoOp(): void
    {
        $client = new RecordingClient();
        $writer = new ElasticsearchAuditWriter($client);

        $writer->writeBatch([]);

        self::assertCount(0, $client->bulkCalls);
    }

    public function testRefreshFlagPassedThrough(): void
    {
        $client = new RecordingClient();
        $writer = new ElasticsearchAuditWriter($client, refresh: true);

        $writer->write($this->event());

        self::assertTrue($client->indexCalls[0]['refresh']);
    }

    public function testSnapshotBundledInDocument(): void
    {
        $client = new RecordingClient();
        $writer = new ElasticsearchAuditWriter($client);

        $event = new AuditEvent(
            type: EventType::ENTITY_UPDATE,
            actor: new Actor(id: 'user-42'),
            target: Target::entity('App\\Entity\\Profile', 7),
            snapshot: Snapshot::full(['name' => 'Alice'], ['name' => 'Alicia'], ['name' => ['old' => 'Alice', 'new' => 'Alicia']]),
            context: AuditContext::empty(),
        );

        $writer->write($event);

        $doc = $client->indexCalls[0]['document'];
        self::assertSame('full', $doc['snapshot']['mode']);
        self::assertSame(['name' => 'Alice'], $doc['snapshot']['pre_image']);
        self::assertSame(['name' => 'Alicia'], $doc['snapshot']['post_image']);
    }

    private function event(?string $at = null): AuditEvent
    {
        return new AuditEvent(
            type: EventType::LOGIN,
            actor: new Actor(id: 'u'),
            target: new Target(),
            snapshot: Snapshot::none(),
            context: AuditContext::empty(),
            occurredAt: $at === null ? null : new \DateTimeImmutable($at),
        );
    }
}

final class RecordingClient implements ElasticsearchClient
{
    /** @var list<array{index:string,id:string,document:array,refresh:bool}> */
    public array $indexCalls = [];
    /** @var list<array{operations:list<array{0:string,1:string,2:array}>,refresh:bool}> */
    public array $bulkCalls = [];
    /** @var list<array{name:string,template:array}> */
    public array $templateCalls = [];

    public function index(string $index, string $id, array $document, bool $refresh = false): void
    {
        $this->indexCalls[] = compact('index', 'id', 'document', 'refresh');
    }

    public function bulk(iterable $operations, bool $refresh = false): void
    {
        $ops = is_array($operations) ? $operations : iterator_to_array($operations);
        $this->bulkCalls[] = ['operations' => $ops, 'refresh' => $refresh];
    }

    public function putIndexTemplate(string $name, array $template): void
    {
        $this->templateCalls[] = compact('name', 'template');
    }

    public function search(string $indexPattern, array $body): array
    {
        return ['hits' => [], 'total' => 0];
    }
}
