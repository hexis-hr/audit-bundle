<?php

declare(strict_types=1);

use Hexis\AuditBundle\Doctrine\AuditableRegistry;
use Hexis\AuditBundle\Doctrine\TablePrefixListener;
use Hexis\AuditBundle\Domain\ContextCollector;
use Hexis\AuditBundle\EventListener\DoctrineAuditListener;
use Hexis\AuditBundle\EventListener\FlushBufferListener;
use Hexis\AuditBundle\EventListener\SecurityAuditSubscriber;
use Hexis\AuditBundle\Serializer\AuditSnapshotNormalizer;
use Hexis\AuditBundle\Storage\AuditWriter;
use Hexis\AuditBundle\Storage\BufferedAuditWriter;
use Hexis\AuditBundle\Storage\DoctrineAuditWriter;
use Hexis\AuditBundle\Command\MigrateLegacyCommand;
use Hexis\AuditBundle\Command\PruneCommand;
use Hexis\AuditBundle\Legacy\DefaultLegacyRowMapper;
use Hexis\AuditBundle\Legacy\LegacyRowMapper;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $services = $container->services()
        ->defaults()
            ->autowire()
            ->autoconfigure();

    $services->load(
        'Hexis\\AuditBundle\\',
        __DIR__ . '/../src/'
    )->exclude([
        __DIR__ . '/../src/Attribute/',
        __DIR__ . '/../src/Domain/',
        __DIR__ . '/../src/Entity/',
        __DIR__ . '/../src/Resources/',
        __DIR__ . '/../src/Storage/',        // Storage writers wired explicitly below.
        __DIR__ . '/../src/Command/',        // Commands wired explicitly (ES command has conditional client).
        __DIR__ . '/../src/AuditBundle.php',
    ]);

    $services->set(ContextCollector::class)
        ->arg('$requestStack', service('request_stack'))
        ->arg('$tokenStorage', service('security.token_storage')->nullOnInvalid())
        ->arg('$captureIp', param('audit.context.capture_ip'))
        ->arg('$captureUserAgent', param('audit.context.capture_user_agent'))
        ->arg('$captureRequestPath', param('audit.context.capture_request_path'))
        ->arg('$captureSessionId', param('audit.context.capture_session_id'));

    $services->set(TablePrefixListener::class)
        ->arg('$targetTable', param('audit.storage.doctrine.table'));

    $services->set(DoctrineAuditWriter::class)
        ->arg('$connection', service('audit.doctrine_connection'))
        ->arg('$table', param('audit.storage.doctrine.table'))
        ->arg('$sourceOfTruth', DoctrineAuditWriter::SOURCE_PRIMARY);

    // Public alias: users inject AuditWriter and receive the buffered facade.
    $services->set(BufferedAuditWriter::class)
        ->arg('$delegate', service(DoctrineAuditWriter::class))
        ->public();

    $services->alias(AuditWriter::class, BufferedAuditWriter::class)->public();

    $services->set(FlushBufferListener::class)
        ->arg('$buffer', service(BufferedAuditWriter::class));

    $services->set(SecurityAuditSubscriber::class)
        ->arg('$writer', service(AuditWriter::class))
        ->arg('$contextCollector', service(ContextCollector::class))
        ->arg('$enabledEvents', param('audit.security.events'))
        ->arg('$enabledFirewalls', param('audit.security.firewalls'));

    $services->set(AuditableRegistry::class)
        ->arg('$yamlOverrides', param('audit.doctrine.classes'))
        ->arg('$additionalIgnored', param('audit.doctrine.ignore_classes'));

    $services->set(AuditSnapshotNormalizer::class);

    // Elasticsearch writer + install command are registered conditionally in AuditBundle::loadExtension,
    // only when audit.storage.elasticsearch.client is set to a host-provided service id.

    $services->set(DefaultLegacyRowMapper::class);
    $services->alias(LegacyRowMapper::class, DefaultLegacyRowMapper::class);

    $services->set(PruneCommand::class)
        ->arg('$connection', service('audit.doctrine_connection'))
        ->arg('$table', param('audit.storage.doctrine.table'))
        ->arg('$defaultRetentionDays', param('audit.storage.doctrine.retention_days'))
        ->tag('console.command');

    $services->set(MigrateLegacyCommand::class)
        ->arg('$connections', service('doctrine'))
        ->arg('$writer', service(AuditWriter::class))
        ->arg('$defaultMapper', service(LegacyRowMapper::class))
        ->arg('$progressDir', param('kernel.project_dir') . '/var/audit')
        ->tag('console.command');

    $services->set(DoctrineAuditListener::class)
        ->arg('$registry', service(AuditableRegistry::class))
        ->arg('$writer', service(AuditWriter::class))
        ->arg('$contextCollector', service(ContextCollector::class))
        ->arg('$snapshotNormalizer', service(AuditSnapshotNormalizer::class))
        ->tag('doctrine.event_listener', ['event' => 'onFlush'])
        ->tag('doctrine.event_listener', ['event' => 'postFlush'])
        ->tag('doctrine.event_listener', ['event' => 'onClear']);
};
