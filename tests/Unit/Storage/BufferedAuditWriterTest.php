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
use Hexis\AuditBundle\Storage\BufferedAuditWriter;
use PHPUnit\Framework\TestCase;

final class BufferedAuditWriterTest extends TestCase
{
    public function testBuffersEventsAndFlushesInBatch(): void
    {
        $delegate = new class implements AuditWriter {
            /** @var list<iterable<AuditEvent>> */
            public array $batches = [];

            public function write(AuditEvent $event): void
            {
                $this->batches[] = [$event];
            }

            public function writeBatch(iterable $events): void
            {
                $this->batches[] = is_array($events) ? $events : iterator_to_array($events);
            }
        };

        $buffer = new BufferedAuditWriter($delegate);
        $buffer->write($this->event());
        $buffer->write($this->event());

        self::assertSame(2, $buffer->count());
        self::assertSame([], $delegate->batches, 'Delegate untouched until flush');

        $buffer->flush();

        self::assertSame(0, $buffer->count());
        self::assertCount(1, $delegate->batches);
        self::assertCount(2, $delegate->batches[0]);
    }

    public function testFlushIsNoOpWhenBufferEmpty(): void
    {
        $delegate = new class implements AuditWriter {
            public bool $called = false;
            public function write(AuditEvent $event): void
            {
                $this->called = true;
            }
            public function writeBatch(iterable $events): void
            {
                $this->called = true;
            }
        };

        $buffer = new BufferedAuditWriter($delegate);
        $buffer->flush();

        self::assertFalse($delegate->called);
    }

    public function testFlushSwallowsDelegateExceptions(): void
    {
        $delegate = new class implements AuditWriter {
            public function write(AuditEvent $event): void
            {
                throw new \RuntimeException('storage down');
            }
            public function writeBatch(iterable $events): void
            {
                throw new \RuntimeException('storage down');
            }
        };

        $buffer = new BufferedAuditWriter($delegate);
        $buffer->write($this->event());

        // Must not throw — audit failures must never reach the request path.
        $buffer->flush();

        self::assertSame(0, $buffer->count(), 'Buffer is cleared before delegate is called');
    }

    public function testDoubleFlushIsSafe(): void
    {
        $delegate = new class implements AuditWriter {
            public int $batchCalls = 0;
            public function write(AuditEvent $event): void
            {
            }
            public function writeBatch(iterable $events): void
            {
                ++$this->batchCalls;
            }
        };

        $buffer = new BufferedAuditWriter($delegate);
        $buffer->write($this->event());
        $buffer->flush();
        $buffer->flush();

        self::assertSame(1, $delegate->batchCalls);
    }

    public function testDropClearsWithoutFlushing(): void
    {
        $delegate = new class implements AuditWriter {
            public bool $called = false;
            public function write(AuditEvent $event): void
            {
                $this->called = true;
            }
            public function writeBatch(iterable $events): void
            {
                $this->called = true;
            }
        };

        $buffer = new BufferedAuditWriter($delegate);
        $buffer->write($this->event());
        $buffer->write($this->event());
        $buffer->drop();

        self::assertSame(0, $buffer->count());
        $buffer->flush();
        self::assertFalse($delegate->called);
    }

    private function event(): AuditEvent
    {
        return new AuditEvent(
            type: EventType::LOGIN,
            actor: new Actor(id: 'u-1'),
            target: new Target(),
            snapshot: Snapshot::none(),
            context: AuditContext::empty(),
        );
    }
}
