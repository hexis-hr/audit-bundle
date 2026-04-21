<?php

declare(strict_types=1);

namespace Hexis\AuditBundle\Storage;

use Hexis\AuditBundle\Domain\AuditEvent;

interface AuditWriter
{
    public function write(AuditEvent $event): void;

    /**
     * @param iterable<AuditEvent> $events
     */
    public function writeBatch(iterable $events): void;
}
