<?php

declare(strict_types=1);

namespace Hexis\AuditBundle\Domain;

use Symfony\Component\Uid\Ulid;

final class AuditEvent
{
    public readonly Ulid $eventId;
    public readonly \DateTimeImmutable $occurredAt;

    public function __construct(
        public readonly EventType $type,
        public readonly Actor $actor,
        public readonly Target $target,
        public readonly Snapshot $snapshot,
        public readonly AuditContext $context,
        public readonly ?string $action = null,
        /**
         * Free-form grouping label used by the viewer UI as a top-level filter — e.g.
         * "security", "doctrine", "User", "AI Chat", "Automation Rule". Distinct from event_type
         * (which is a typed enum). Security + Doctrine listeners set sensible defaults; custom
         * callers set whatever fits their taxonomy.
         */
        public readonly ?string $source = null,
        ?Ulid $eventId = null,
        ?\DateTimeImmutable $occurredAt = null,
    ) {
        $this->eventId = $eventId ?? new Ulid();
        $this->occurredAt = $occurredAt ?? new \DateTimeImmutable();
    }
}
