<?php

declare(strict_types=1);

namespace Hexis\AuditBundle\Tests\Unit\Storage;

use Hexis\AuditBundle\Domain\Actor;
use Hexis\AuditBundle\Domain\AuditContext;
use Hexis\AuditBundle\Domain\AuditEvent;
use Hexis\AuditBundle\Domain\EventType;
use Hexis\AuditBundle\Domain\Snapshot;
use Hexis\AuditBundle\Domain\Target;
use Hexis\AuditBundle\Storage\AuditWriter;
use Hexis\AuditBundle\Storage\ChainedAuditWriter;
use PHPUnit\Framework\TestCase;

final class ChainedAuditWriterTest extends TestCase
{
    public function testPrimarySuccessSkipsFallback(): void
    {
        $primary = new CapturingWriter();
        $fallback = new CapturingWriter();

        $chain = new ChainedAuditWriter($primary, $fallback);
        $chain->write($this->event());

        self::assertCount(1, $primary->seen);
        self::assertCount(0, $fallback->seen);
    }

    public function testPrimaryFailureFallsThrough(): void
    {
        $primary = new FailingWriter();
        $fallback = new CapturingWriter();

        $chain = new ChainedAuditWriter($primary, $fallback);
        $chain->write($this->event());

        self::assertCount(1, $fallback->seen);
    }

    public function testBothFailSwallowsExceptions(): void
    {
        $primary = new FailingWriter();
        $fallback = new FailingWriter();

        $chain = new ChainedAuditWriter($primary, $fallback);
        // Must not throw — audit failures must never reach the request path.
        $chain->write($this->event());

        $this->addToAssertionCount(1);
    }

    public function testBatchPrimarySuccessSkipsFallback(): void
    {
        $primary = new CapturingWriter();
        $fallback = new CapturingWriter();

        $chain = new ChainedAuditWriter($primary, $fallback);
        $chain->writeBatch([$this->event(), $this->event()]);

        self::assertCount(2, $primary->seen);
        self::assertCount(0, $fallback->seen);
    }

    public function testBatchPrimaryFailsFallsThroughWholeBatch(): void
    {
        $primary = new FailingWriter();
        $fallback = new CapturingWriter();

        $chain = new ChainedAuditWriter($primary, $fallback);
        $chain->writeBatch([$this->event(), $this->event(), $this->event()]);

        self::assertCount(3, $fallback->seen);
    }

    public function testEmptyBatchIsNoOp(): void
    {
        $primary = new FailingWriter();
        $fallback = new FailingWriter();

        $chain = new ChainedAuditWriter($primary, $fallback);
        $chain->writeBatch([]);

        $this->addToAssertionCount(1);
    }

    private function event(): AuditEvent
    {
        return new AuditEvent(
            type: EventType::LOGIN,
            actor: new Actor(id: 'u'),
            target: new Target(),
            snapshot: Snapshot::none(),
            context: AuditContext::empty(),
        );
    }
}

final class CapturingWriter implements AuditWriter
{
    /** @var list<AuditEvent> */
    public array $seen = [];

    public function write(AuditEvent $event): void
    {
        $this->seen[] = $event;
    }

    public function writeBatch(iterable $events): void
    {
        foreach ($events as $event) {
            $this->seen[] = $event;
        }
    }
}

final class FailingWriter implements AuditWriter
{
    public function write(AuditEvent $event): void
    {
        throw new \RuntimeException('primary down');
    }

    public function writeBatch(iterable $events): void
    {
        throw new \RuntimeException('primary down');
    }
}
