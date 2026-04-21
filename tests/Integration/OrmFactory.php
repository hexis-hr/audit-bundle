<?php

declare(strict_types=1);

namespace Hexis\AuditBundle\Tests\Integration;

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;

/**
 * Builds a minimal EntityManager over in-memory SQLite with the Fixtures/ entities mapped,
 * plus the bundle's audit_log table materialized via SchemaFactory.
 */
final class OrmFactory
{
    public static function create(): EntityManager
    {
        $config = ORMSetup::createAttributeMetadataConfiguration(
            paths: [__DIR__ . '/Fixtures'],
            isDevMode: true,
        );

        $connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ], $config);

        $em = new EntityManager($connection, $config);

        // Create schema for the test entities.
        $schemaTool = new SchemaTool($em);
        $schemaTool->createSchema($em->getMetadataFactory()->getAllMetadata());

        // And the audit_log table — uses the same in-memory connection.
        SchemaFactory::install($connection);

        return $em;
    }
}
