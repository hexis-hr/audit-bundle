<?php

declare(strict_types=1);

namespace Hexis\AuditBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Widens `actor_id` and `impersonator_id` from 64 to 255 characters: they hold the user identifier as is,
 * often an email address, and on MySQL in strict mode a longer one failed the insert. The index on
 * (actor_id, occurred_at) is kept. Idempotent — skips a connection without the table.
 */
final class Version20261022000001 extends AbstractMigration
{
    private const TABLE = 'hexis_audit_log';
    private const COLUMNS = ['actor_id', 'impersonator_id'];

    public function getDescription(): string
    {
        return 'AuditBundle: widen hexis_audit_log actor_id and impersonator_id to 255 characters.';
    }

    public function up(Schema $schema): void
    {
        $this->resize($schema, 255);
    }

    /**
     * On MySQL in strict mode this fails while a stored identifier is longer than 64 characters.
     */
    public function down(Schema $schema): void
    {
        $this->resize($schema, 64);
    }

    private function resize(Schema $schema, int $length): void
    {
        if (!$schema->hasTable(self::TABLE)) {
            // Table isn't on this connection (non-audit EM in a multi-EM setup) — nothing to do.
            return;
        }

        $table = $schema->getTable(self::TABLE);
        foreach (self::COLUMNS as $column) {
            if ($table->hasColumn($column)) {
                $table->getColumn($column)->setLength($length);
            }
        }
    }
}
