<?php

declare(strict_types=1);

namespace Hexis\AuditBundle;

use Hexis\AuditBundle\Command\DrainFallbackCommand;
use Hexis\AuditBundle\Command\InstallElasticsearchTemplateCommand;
use Hexis\AuditBundle\Storage\BufferedAuditWriter;
use Hexis\AuditBundle\Storage\ChainedAuditWriter;
use Hexis\AuditBundle\Storage\AuditReader;
use Hexis\AuditBundle\Storage\DoctrineAuditReader;
use Hexis\AuditBundle\Storage\DoctrineAuditWriter;
use Hexis\AuditBundle\Storage\Elasticsearch\ElasticsearchClient;
use Hexis\AuditBundle\Storage\ElasticsearchAuditReader;
use Hexis\AuditBundle\Storage\ElasticsearchAuditWriter;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

final class AuditBundle extends AbstractBundle
{
    protected string $extensionAlias = 'audit';

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->booleanNode('enabled')->defaultTrue()->end()
                ->arrayNode('environments')
                    ->info('Kernel environments in which capture is active.')
                    ->scalarPrototype()->end()
                    ->defaultValue(['prod', 'dev'])
                ->end()
                ->arrayNode('security')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')->defaultTrue()->end()
                        ->arrayNode('events')
                            ->enumPrototype()
                                ->values(['login', 'login_failure', 'logout', 'switch_user'])
                            ->end()
                            ->defaultValue(['login', 'login_failure', 'logout', 'switch_user'])
                        ->end()
                        ->arrayNode('firewalls')
                            ->info('Firewall names to capture. null or empty = all firewalls.')
                            ->scalarPrototype()->end()
                            ->defaultValue([])
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('doctrine')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')->defaultTrue()->end()
                        ->arrayNode('entity_managers')
                            ->info('EntityManager names whose writes are captured. Listener attaches per EM.')
                            ->scalarPrototype()->end()
                            ->defaultValue(['default'])
                        ->end()
                        ->arrayNode('ignore_classes')
                            ->info('FQCNs never audited. Bundle-owned entities are always ignored regardless.')
                            ->scalarPrototype()->end()
                            ->defaultValue([])
                        ->end()
                        ->arrayNode('classes')
                            ->info('YAML override for #[Auditable]. Key = FQCN; value = { mode, ignore_fields }.')
                            ->useAttributeAsKey('class')
                            ->arrayPrototype()
                                ->children()
                                    ->enumNode('mode')
                                        ->values(['none', 'changed_fields', 'full'])
                                        ->defaultValue('none')
                                    ->end()
                                    ->arrayNode('ignore_fields')
                                        ->scalarPrototype()->end()
                                        ->defaultValue([])
                                    ->end()
                                ->end()
                            ->end()
                            ->defaultValue([])
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('storage')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->enumNode('primary')
                            ->values(['doctrine', 'elasticsearch'])
                            ->defaultValue('doctrine')
                        ->end()
                        ->scalarNode('fallback')
                            ->info('Fallback writer when primary throws. null disables fallback; "doctrine" is the only supported value today.')
                            ->defaultNull()
                            ->validate()
                                ->ifTrue(static fn ($v) => $v !== null && $v !== 'doctrine')
                                ->thenInvalid('Invalid audit.storage.fallback %s. Allowed: null, "doctrine".')
                            ->end()
                        ->end()
                        ->arrayNode('elasticsearch')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->scalarNode('client')
                                    ->info('Service id of a configured Elasticsearch\\Client. null disables ES writer.')
                                    ->defaultNull()
                                ->end()
                                ->scalarNode('index')
                                    ->info('Index name pattern. strftime tokens supported, e.g. "audit-%Y.%m".')
                                    ->defaultValue('audit-%Y.%m')
                                ->end()
                                ->scalarNode('index_template_name')->defaultValue('audit')->end()
                                ->booleanNode('refresh')->defaultFalse()->end()
                            ->end()
                        ->end()
                        ->arrayNode('doctrine')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->scalarNode('connection')->defaultValue('default')->end()
                                ->scalarNode('entity_manager')
                                    ->info('EntityManager name to attach the AuditBundle Doctrine mapping to. null = default EM.')
                                    ->defaultNull()
                                ->end()
                                ->scalarNode('table_prefix')
                                    ->info('Prefix for the bundle\'s table. Used only when `table` is null.')
                                    ->defaultValue('hexis_audit_')
                                ->end()
                                ->scalarNode('table')
                                    ->info('Full table name. Overrides table_prefix when set. null = <table_prefix>log (default: hexis_audit_log).')
                                    ->defaultNull()
                                ->end()
                                ->integerNode('retention_days')->defaultValue(365)->min(1)->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('snapshots')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->enumNode('default_mode')
                            ->values(['none', 'changed_fields', 'full'])
                            ->defaultValue('none')
                        ->end()
                        ->scalarNode('serializer_group')->defaultValue('audit:snapshot')->end()
                    ->end()
                ->end()
                ->arrayNode('context')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('capture_ip')->defaultTrue()->end()
                        ->booleanNode('capture_user_agent')->defaultTrue()->end()
                        ->booleanNode('capture_request_path')->defaultTrue()->end()
                        ->booleanNode('capture_session_id')
                            ->info('Stores SHA-256 hash of the session id, never the raw value. Null when no session (CLI, stateless API, workers).')
                            ->defaultTrue()
                        ->end()
                    ->end()
                ->end()
                ->scalarNode('scrubber')
                    ->info('Service id of a custom PiiScrubber. null = default.')
                    ->defaultNull()
                ->end()
                ->arrayNode('async')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('transport')
                            ->info('Messenger transport name for async flush. null = sync flush on kernel.terminate.')
                            ->defaultNull()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('compat')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('write_legacy_table')
                            ->info('During migration from a host-owned audit table, also write rows there. Default false.')
                            ->defaultFalse()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('ui')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')->defaultTrue()->end()
                        ->scalarNode('route_prefix')->defaultValue('/_audit')->end()
                        ->scalarNode('role')
                            ->info('Security role required to view the audit UI.')
                            ->defaultValue('ROLE_ADMIN')
                        ->end()
                        ->integerNode('page_size')->defaultValue(50)->min(1)->end()
                    ->end()
                ->end()
            ->end();
    }

    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->import(__DIR__ . '/../config/services.php');

        $builder->setParameter('audit.enabled', (bool) $config['enabled']);
        $builder->setParameter('audit.environments', (array) $config['environments']);

        $builder->setParameter('audit.security.enabled', (bool) $config['security']['enabled']);
        $builder->setParameter('audit.security.events', (array) $config['security']['events']);
        $builder->setParameter('audit.security.firewalls', (array) $config['security']['firewalls']);

        $builder->setParameter('audit.doctrine.enabled', (bool) $config['doctrine']['enabled']);
        $builder->setParameter('audit.doctrine.entity_managers', (array) $config['doctrine']['entity_managers']);
        $builder->setParameter('audit.doctrine.ignore_classes', (array) $config['doctrine']['ignore_classes']);
        $builder->setParameter('audit.doctrine.classes', (array) $config['doctrine']['classes']);

        $builder->setParameter('audit.storage.primary', (string) $config['storage']['primary']);
        $builder->setParameter('audit.storage.fallback', $config['storage']['fallback']);

        $builder->setParameter('audit.storage.elasticsearch.client', $config['storage']['elasticsearch']['client']);
        // Double literal % signs so the Symfony parameter bag doesn't treat strftime tokens (e.g. %Y, %m) as parameter refs.
        $builder->setParameter('audit.storage.elasticsearch.index', str_replace('%', '%%', (string) $config['storage']['elasticsearch']['index']));
        $builder->setParameter('audit.storage.elasticsearch.index_template_name', (string) $config['storage']['elasticsearch']['index_template_name']);
        $builder->setParameter('audit.storage.elasticsearch.refresh', (bool) $config['storage']['elasticsearch']['refresh']);

        $builder->setParameter('audit.storage.doctrine.connection', (string) $config['storage']['doctrine']['connection']);
        $builder->setParameter('audit.storage.doctrine.entity_manager', $config['storage']['doctrine']['entity_manager']);
        $builder->setParameter('audit.storage.doctrine.table_prefix', (string) $config['storage']['doctrine']['table_prefix']);
        $builder->setParameter('audit.storage.doctrine.retention_days', (int) $config['storage']['doctrine']['retention_days']);

        $builder->setParameter('audit.snapshots.default_mode', (string) $config['snapshots']['default_mode']);
        $builder->setParameter('audit.snapshots.serializer_group', (string) $config['snapshots']['serializer_group']);

        $builder->setParameter('audit.context.capture_ip', (bool) $config['context']['capture_ip']);
        $builder->setParameter('audit.context.capture_user_agent', (bool) $config['context']['capture_user_agent']);
        $builder->setParameter('audit.context.capture_request_path', (bool) $config['context']['capture_request_path']);
        $builder->setParameter('audit.context.capture_session_id', (bool) $config['context']['capture_session_id']);

        $builder->setParameter('audit.scrubber', $config['scrubber']);
        $builder->setParameter('audit.async.transport', $config['async']['transport']);
        $builder->setParameter('audit.compat.write_legacy_table', (bool) $config['compat']['write_legacy_table']);

        $builder->setParameter('audit.ui.enabled', (bool) $config['ui']['enabled']);
        $builder->setParameter('audit.ui.route_prefix', (string) $config['ui']['route_prefix']);
        $builder->setParameter('audit.ui.role', (string) $config['ui']['role']);
        $builder->setParameter('audit.ui.page_size', (int) $config['ui']['page_size']);

        $tablePrefix = (string) $config['storage']['doctrine']['table_prefix'];
        $tableOverride = $config['storage']['doctrine']['table'] ?? null;
        $tableName = ($tableOverride !== null && $tableOverride !== '') ? (string) $tableOverride : $tablePrefix . 'log';
        $builder->setParameter('audit.storage.doctrine.table', $tableName);

        $builder->setAlias(
            'audit.doctrine_connection',
            sprintf('doctrine.dbal.%s_connection', $config['storage']['doctrine']['connection']),
        );

        $this->registerElasticsearchServices($builder, $config['storage']['elasticsearch']);
        $this->wireStorageChain($builder, $config['storage']);
        $this->registerDrainCommand($builder, $config['storage']);
        $this->wireAuditReader($builder, $config['storage']);
    }

    /**
     * @param array{primary: string, elasticsearch: array, doctrine: array} $storage
     */
    private function wireAuditReader(ContainerBuilder $builder, array $storage): void
    {
        // Reader follows the primary writer: if you write to ES, you read from ES. If ES isn't
        // available (no client configured), reader falls back to Doctrine so the UI still works.
        if ($storage['primary'] === 'elasticsearch'
            && $storage['elasticsearch']['client'] !== null
            && $storage['elasticsearch']['client'] !== ''
        ) {
            $builder->setDefinition(ElasticsearchAuditReader::class, new Definition(ElasticsearchAuditReader::class, [
                '$client' => new Reference(ElasticsearchClient::class),
                '$indexPattern' => $this->deriveEsSearchPattern($storage['elasticsearch']['index']),
            ]));
            $builder->setAlias(AuditReader::class, ElasticsearchAuditReader::class)->setPublic(false);
            return;
        }

        $builder->setDefinition(DoctrineAuditReader::class, new Definition(DoctrineAuditReader::class, [
            '$connection' => new Reference('audit.doctrine_connection'),
            '$table' => $builder->getParameter('audit.storage.doctrine.table'),
        ]));
        $builder->setAlias(AuditReader::class, DoctrineAuditReader::class)->setPublic(false);
    }

    /**
     * `audit-%Y.%m` → `audit-*` so search spans all rolled-over indices.
     */
    private function deriveEsSearchPattern(string $indexPattern): string
    {
        $pattern = preg_replace('/%[Ymd]+.*$/', '*', $indexPattern);
        return $pattern ?: 'audit-*';
    }

    /**
     * @param array{primary: string, fallback: ?string, elasticsearch: array, doctrine: array} $storage
     */
    private function wireStorageChain(ContainerBuilder $builder, array $storage): void
    {
        $primaryId = $this->resolvePrimaryWriterServiceId($storage);
        if ($primaryId === null) {
            // Primary unavailable (e.g. primary=elasticsearch but no client configured) —
            // leave services.php defaults (BufferedAuditWriter → DoctrineAuditWriter).
            return;
        }

        if ($storage['primary'] !== 'doctrine') {
            $builder->getDefinition(BufferedAuditWriter::class)
                ->setArgument('$delegate', new Reference($primaryId));
        }

        if ($storage['fallback'] !== 'doctrine') {
            return;
        }

        // Register a second Doctrine writer with source_of_truth='fallback' and chain primary + fallback.
        $fallbackId = 'audit.fallback_writer';
        $builder->setDefinition($fallbackId, new Definition(DoctrineAuditWriter::class, [
            '$connection' => new Reference('audit.doctrine_connection'),
            '$table' => $storage['doctrine']['table_prefix'] . 'log',
            '$sourceOfTruth' => DoctrineAuditWriter::SOURCE_FALLBACK,
        ]));

        $builder->setDefinition(ChainedAuditWriter::class, new Definition(ChainedAuditWriter::class, [
            '$primary' => new Reference($primaryId),
            '$fallback' => new Reference($fallbackId),
        ]));

        $builder->getDefinition(BufferedAuditWriter::class)
            ->setArgument('$delegate', new Reference(ChainedAuditWriter::class));
    }

    /**
     * @param array{primary: string, fallback: ?string, doctrine: array} $storage
     */
    private function registerDrainCommand(ContainerBuilder $builder, array $storage): void
    {
        if ($storage['fallback'] !== 'doctrine' || $storage['primary'] === 'doctrine') {
            return;
        }

        $primaryId = $this->resolvePrimaryWriterServiceId($storage);
        if ($primaryId === null) {
            return;
        }

        $definition = new Definition(DrainFallbackCommand::class, [
            '$connection' => new Reference('audit.doctrine_connection'),
            '$table' => $storage['doctrine']['table_prefix'] . 'log',
            '$primary' => new Reference($primaryId),
        ]);
        $definition->addTag('console.command');
        $builder->setDefinition(DrainFallbackCommand::class, $definition);
    }

    /**
     * @param array{primary: string, elasticsearch: array} $storage
     */
    private function resolvePrimaryWriterServiceId(array $storage): ?string
    {
        return match ($storage['primary']) {
            'doctrine' => DoctrineAuditWriter::class,
            'elasticsearch' => ($storage['elasticsearch']['client'] !== null && $storage['elasticsearch']['client'] !== '')
                ? ElasticsearchAuditWriter::class
                : null,
            default => null,
        };
    }

    /**
     * @param array{client: ?string, index: string, index_template_name: string, refresh: bool} $esConfig
     */
    private function registerElasticsearchServices(ContainerBuilder $builder, array $esConfig): void
    {
        // No host-provided client = no ES services registered. Host can still use Doctrine-only setups.
        if ($esConfig['client'] === null || $esConfig['client'] === '') {
            return;
        }

        // Alias our interface to whatever the host wired up (presumably an adapter around elastic/elasticsearch).
        $builder->setAlias(ElasticsearchClient::class, $esConfig['client']);

        // Writer service — explicit registration so arguments are clear and the class is only
        // instantiated when it's actually used.
        $writerDefinition = new Definition(ElasticsearchAuditWriter::class, [
            '$client' => new Reference(ElasticsearchClient::class),
            '$indexPattern' => $esConfig['index'],
            '$refresh' => $esConfig['refresh'],
        ]);
        $builder->setDefinition(ElasticsearchAuditWriter::class, $writerDefinition);

        // Install-template command.
        $commandDefinition = new Definition(InstallElasticsearchTemplateCommand::class, [
            '$client' => new Reference(ElasticsearchClient::class),
            '$templateName' => $esConfig['index_template_name'],
        ]);
        $commandDefinition->addTag('console.command');
        $builder->setDefinition(InstallElasticsearchTemplateCommand::class, $commandDefinition);
    }

    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $bundlePath = \dirname(__DIR__);
        $entityManager = $this->resolveConfiguredEntityManager($builder);

        if ($builder->hasExtension('doctrine')) {
            $mapping = [
                'AuditBundle' => [
                    'type' => 'xml',
                    'dir' => $bundlePath . '/src/Resources/config/doctrine',
                    'prefix' => 'Hexis\\AuditBundle\\Entity',
                    'alias' => 'AuditBundle',
                    'is_bundle' => false,
                ],
            ];

            $ormConfig = $entityManager === null
                ? ['mappings' => $mapping]
                : ['entity_managers' => [$entityManager => ['mappings' => $mapping]]];

            $container->extension('doctrine', ['orm' => $ormConfig]);
        }

        if ($builder->hasExtension('doctrine_migrations')) {
            $container->extension('doctrine_migrations', [
                'migrations_paths' => [
                    'Hexis\\AuditBundle\\Migrations' => $bundlePath . '/src/Resources/migrations',
                ],
            ]);
        }

        if ($builder->hasExtension('twig')) {
            $paths = [];
            // Host override path wins the Twig lookup.
            $overrideDir = $builder->getParameter('kernel.project_dir') . '/templates/bundles/AuditBundle';
            if (is_dir($overrideDir)) {
                $paths[$overrideDir] = 'AuditBundle';
            }
            $paths[$bundlePath . '/src/Resources/views'] = 'AuditBundle';
            $container->extension('twig', ['paths' => $paths]);
        }
    }

    /**
     * prependExtension runs before loadExtension, so the bundle's config tree isn't
     * processed yet — scan raw configs and take the last-wins value.
     */
    private function resolveConfiguredEntityManager(ContainerBuilder $builder): ?string
    {
        foreach (array_reverse($builder->getExtensionConfig('audit')) as $config) {
            if (isset($config['storage']['doctrine']['entity_manager'])
                && $config['storage']['doctrine']['entity_manager'] !== null
                && $config['storage']['doctrine']['entity_manager'] !== ''
            ) {
                return (string) $config['storage']['doctrine']['entity_manager'];
            }
        }

        return null;
    }
}
