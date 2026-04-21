<?php

declare(strict_types=1);

namespace Hexis\AuditBundle\Legacy;

use Hexis\AuditBundle\Domain\AuditEvent;

interface LegacyRowMapper
{
    /**
     * Map a single row from a legacy audit table into an AuditEvent.
     *
     * Return null to skip the row (e.g. when a mapper can't confidently infer the event type
     * and the host wants to drop unmappable data rather than tag it CUSTOM).
     *
     * @param array<string, mixed> $row
     */
    public function map(array $row): ?AuditEvent;
}
