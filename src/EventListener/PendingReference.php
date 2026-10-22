<?php

declare(strict_types=1);

namespace Hexis\AuditBundle\EventListener;

/**
 * Placeholder for a reference to an entity whose identifier is not assigned until after the flush.
 *
 * Staged diffs and images otherwise hold only scalars and arrays, so an object in them can only be
 * this placeholder: DoctrineAuditListener::postFlush() replaces it with the `{@ref, id}` reference
 * without inspecting array keys, which keeps application data free to use any key.
 *
 * @internal
 */
final class PendingReference
{
    public function __construct(
        public readonly object $entity,
    ) {
    }
}
