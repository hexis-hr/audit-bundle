<?php

declare(strict_types=1);

namespace Hexis\AuditBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds the `source` column + index to `hexis_audit_log` for deployments that ran the V1
 * migration before `source` was introduced. Idempotent — skips when the column already exists.
 */
final class Version20260421000002 extends AbstractMigration
{
    private const TABLE = 'hexis_audit_log';

    public function getDescription(): string
    {
        return 'AuditBundle: add `source` column + index to hexis_audit_log.';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable(self::TABLE)) {
            // Table isn't on this connection (non-audit EM in a multi-EM setup) — nothing to do.
            return;
        }

        $table = $schema->getTable(self::TABLE);
        if (!$table->hasColumn('source')) {
            $table->addColumn('source', 'string', ['length' => 64, 'notnull' => false]);
        }

        if (!$table->hasIndex('idx_hexis_audit_source_occurred')) {
            $table->addIndex(['source', 'occurred_at'], 'idx_hexis_audit_source_occurred');
        }
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable(self::TABLE)) {
            return;
        }

        $table = $schema->getTable(self::TABLE);
        if ($table->hasIndex('idx_hexis_audit_source_occurred')) {
            $table->dropIndex('idx_hexis_audit_source_occurred');
        }
        if ($table->hasColumn('source')) {
            $table->dropColumn('source');
        }
    }
}
