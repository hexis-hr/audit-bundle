<?php

declare(strict_types=1);

namespace Hexis\AuditBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Audit Bundle initial schema: single `hexis_audit_log` table.
 *
 * Uses the Schema API so the generated DDL adapts to the active DBAL platform
 * (MySQL, MariaDB, PostgreSQL, SQLite).
 *
 * Table name note: this migration creates `hexis_audit_log` (the vendor-namespaced default).
 * If you override `audit.storage.doctrine.table` or `table_prefix` to a different name, copy
 * this migration into your app's migrations/ directory and rename the table. The bundle's
 * TablePrefixListener handles ORM-side runtime rewrites, but DDL is static — the migration
 * creates whatever name is in this file.
 */
final class Version20260421000001 extends AbstractMigration
{
    private const TABLE = 'hexis_audit_log';

    public function getDescription(): string
    {
        return 'AuditBundle: create hexis_audit_log table.';
    }

    public function up(Schema $schema): void
    {
        // Idempotent: if a table with the same name already exists on this connection (e.g. a legacy
        // host-owned audit_log on the default database), skip creation. The bundle's real schema
        // should still be created on the connection it's configured against — run this migration
        // explicitly with `--em=<name>` to target that EM.
        if ($schema->hasTable(self::TABLE)) {
            $this->warnIf(true, sprintf(
                'Table "%s" already exists on this connection — skipping. If this connection is NOT where you want the bundle\'s audit storage, run doctrine:migrations:migrate --em=<your-audit-em>.',
                self::TABLE,
            ));
            return;
        }

        $table = $schema->createTable(self::TABLE);
        $table->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
        $table->addColumn('event_id', 'string', ['length' => 26, 'notnull' => true]);
        $table->addColumn('event_type', 'string', ['length' => 32, 'notnull' => true]);
        $table->addColumn('source', 'string', ['length' => 64, 'notnull' => false]);
        $table->addColumn('occurred_at', 'datetime_immutable', ['notnull' => true]);

        $table->addColumn('actor_id', 'string', ['length' => 64, 'notnull' => false]);
        $table->addColumn('actor_type', 'string', ['length' => 255, 'notnull' => false]);
        $table->addColumn('actor_firewall', 'string', ['length' => 32, 'notnull' => false]);
        $table->addColumn('impersonator_id', 'string', ['length' => 64, 'notnull' => false]);

        $table->addColumn('target_class', 'string', ['length' => 255, 'notnull' => false]);
        $table->addColumn('target_id', 'string', ['length' => 64, 'notnull' => false]);
        $table->addColumn('action', 'string', ['length' => 64, 'notnull' => false]);

        $table->addColumn('ip_address', 'string', ['length' => 45, 'notnull' => false]);
        $table->addColumn('user_agent', 'text', ['notnull' => false]);
        $table->addColumn('request_method', 'string', ['length' => 10, 'notnull' => false]);
        $table->addColumn('request_path', 'string', ['length' => 1024, 'notnull' => false]);
        $table->addColumn('session_id_hash', 'string', ['length' => 64, 'notnull' => false]);

        $table->addColumn('snapshot_mode', 'string', ['length' => 16, 'notnull' => false]);
        $table->addColumn('pre_image', 'json', ['notnull' => false]);
        $table->addColumn('post_image', 'json', ['notnull' => false]);
        $table->addColumn('diff', 'json', ['notnull' => false]);
        $table->addColumn('context', 'json', ['notnull' => false]);

        $table->addColumn('source_of_truth', 'string', ['length' => 16, 'notnull' => true, 'default' => 'primary']);
        $table->addColumn('pending_replay_at', 'datetime_immutable', ['notnull' => false]);

        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['event_id'], 'uniq_hexis_audit_event_id');
        $table->addIndex(['occurred_at'], 'idx_hexis_audit_occurred_at');
        $table->addIndex(['actor_id', 'occurred_at'], 'idx_hexis_audit_actor_occurred');
        $table->addIndex(['target_class', 'target_id', 'occurred_at'], 'idx_hexis_audit_target');
        $table->addIndex(['event_type', 'occurred_at'], 'idx_hexis_audit_type_occurred');
        $table->addIndex(['source', 'occurred_at'], 'idx_hexis_audit_source_occurred');
        $table->addIndex(['session_id_hash', 'occurred_at'], 'idx_hexis_audit_session');
        $table->addIndex(['source_of_truth', 'pending_replay_at'], 'idx_hexis_audit_replay');
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable(self::TABLE)) {
            $schema->dropTable(self::TABLE);
        }
    }
}
