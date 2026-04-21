<?php

declare(strict_types=1);

namespace Hexis\AuditBundle\Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;

/**
 * Builds an in-memory SQLite connection with the bundle's audit_log schema materialized.
 * Integration tests use this instead of booting a full Symfony kernel.
 */
final class SchemaFactory
{
    public const TABLE = 'hexis_audit_log';

    public static function create(): Connection
    {
        $connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);

        self::install($connection);

        return $connection;
    }

    public static function install(Connection $connection): void
    {
        $connection->executeStatement('
            CREATE TABLE ' . self::TABLE . ' (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                event_id VARCHAR(26) NOT NULL UNIQUE,
                event_type VARCHAR(32) NOT NULL,
                source VARCHAR(64),
                occurred_at DATETIME NOT NULL,
                actor_id VARCHAR(64),
                actor_type VARCHAR(255),
                actor_firewall VARCHAR(32),
                impersonator_id VARCHAR(64),
                target_class VARCHAR(255),
                target_id VARCHAR(64),
                action VARCHAR(64),
                ip_address VARCHAR(45),
                user_agent TEXT,
                request_method VARCHAR(10),
                request_path VARCHAR(1024),
                session_id_hash VARCHAR(64),
                snapshot_mode VARCHAR(16),
                pre_image TEXT,
                post_image TEXT,
                diff TEXT,
                context TEXT,
                source_of_truth VARCHAR(16) NOT NULL DEFAULT \'primary\',
                pending_replay_at DATETIME
            )
        ');
    }
}
