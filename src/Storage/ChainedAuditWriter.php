<?php

declare(strict_types=1);

namespace Hexis\AuditBundle\Storage;

use Hexis\AuditBundle\Domain\AuditEvent;

/**
 * Tries primary; on exception, falls through to fallback. The original exception is preserved
 * and logged via error_log but never re-thrown — audit failures must not break the request path.
 *
 * Rows that end up in fallback storage are marked with source_of_truth='fallback' and a
 * pending_replay_at timestamp by the DoctrineAuditWriter; the audit:drain-fallback command
 * sweeps them forward to the primary in batches.
 */
final class ChainedAuditWriter implements AuditWriter
{
    public function __construct(
        private readonly AuditWriter $primary,
        private readonly AuditWriter $fallback,
    ) {
    }

    public function write(AuditEvent $event): void
    {
        try {
            $this->primary->write($event);
        } catch (\Throwable $primaryException) {
            $this->recordPrimaryFailure($primaryException, 1);
            try {
                $this->fallback->write($event);
            } catch (\Throwable $fallbackException) {
                $this->recordFallbackFailure($fallbackException, 1);
            }
        }
    }

    public function writeBatch(iterable $events): void
    {
        $buffered = [];
        foreach ($events as $event) {
            $buffered[] = $event;
        }

        if ($buffered === []) {
            return;
        }

        try {
            $this->primary->writeBatch($buffered);
            return;
        } catch (\Throwable $primaryException) {
            $this->recordPrimaryFailure($primaryException, \count($buffered));
        }

        try {
            $this->fallback->writeBatch($buffered);
        } catch (\Throwable $fallbackException) {
            $this->recordFallbackFailure($fallbackException, \count($buffered));
        }
    }

    private function recordPrimaryFailure(\Throwable $e, int $count): void
    {
        error_log(sprintf(
            '[audit-bundle] primary writer failed (%d event(s)) — falling back: %s: %s',
            $count,
            $e::class,
            $e->getMessage(),
        ));
    }

    private function recordFallbackFailure(\Throwable $e, int $count): void
    {
        error_log(sprintf(
            '[audit-bundle] FALLBACK writer ALSO failed (%d event(s) LOST): %s: %s',
            $count,
            $e::class,
            $e->getMessage(),
        ));
    }
}
