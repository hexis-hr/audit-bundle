<?php

declare(strict_types=1);

namespace Hexis\AuditBundle\Storage;

use Hexis\AuditBundle\Domain\AuditEvent;

/**
 * Collects events in memory for the lifetime of the request/command, then hands them to the
 * delegate writer in one batch on flush. Exceptions during flush are swallowed and logged to
 * error_log — the audit pipeline must never fail the request path.
 */
final class BufferedAuditWriter implements AuditWriter
{
    /** @var list<AuditEvent> */
    private array $buffer = [];

    public function __construct(private readonly AuditWriter $delegate)
    {
    }

    public function write(AuditEvent $event): void
    {
        $this->buffer[] = $event;
    }

    public function writeBatch(iterable $events): void
    {
        foreach ($events as $event) {
            $this->buffer[] = $event;
        }
    }

    /**
     * Drains the buffer into the delegate. Clears the buffer before delegating so a
     * re-entrant flush (e.g. via a shutdown handler that also fires) is a no-op.
     */
    public function flush(): void
    {
        if ($this->buffer === []) {
            return;
        }

        $pending = $this->buffer;
        $this->buffer = [];

        try {
            $this->delegate->writeBatch($pending);
        } catch (\Throwable $e) {
            error_log(sprintf(
                '[audit-bundle] flush failed, %d event(s) dropped: %s: %s',
                \count($pending),
                $e::class,
                $e->getMessage(),
            ));
        }
    }

    public function drop(): void
    {
        $this->buffer = [];
    }

    public function count(): int
    {
        return \count($this->buffer);
    }
}
