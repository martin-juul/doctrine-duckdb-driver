<?php

declare(strict_types=1);

namespace MartinJuul\DBAL\DuckDB\Bridge\Symfony;

use MartinJuul\DBAL\DuckDB\Driver;
use MartinJuul\DBAL\DuckDB\Driver\Middleware\LogSchemaNotices;
use MartinJuul\DBAL\DuckDB\Driver\Middleware\OmitForeignKeys;
use MartinJuul\DBAL\DuckDB\ORM\JoinedSubclassRowsListener;
use MartinJuul\DBAL\DuckDB\Types\TypeRegistrar;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * Configures DoctrineBundle for DuckDB: registers the package's types and the
 * `duckdb` URL scheme, makes every entity manager generate default IDs from
 * sequences on DuckDB, registers {@see JoinedSubclassRowsListener} on every
 * connection's event manager, logs schema notices, such as a dropped column
 * collation, to the `doctrine` log channel, and omits foreign keys on the
 * connections listed under `duckdb.omit_foreign_keys`. Other connections are
 * left unchanged.
 */
final class DuckDBBundle extends AbstractBundle
{
    public const string MIDDLEWARE_SERVICE = 'duckdb.omit_foreign_keys_middleware';

    public const string JOINED_SUBCLASS_ROWS_LISTENER_SERVICE = 'duckdb.joined_subclass_rows_listener';

    public const string LOG_SCHEMA_NOTICES_MIDDLEWARE_SERVICE = 'duckdb.log_schema_notices_middleware';

    protected string $extensionAlias = 'duckdb';

    public function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new IdentityGenerationPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION);
    }

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->arrayNode('omit_foreign_keys')
                    ->info('Names of the DBAL connections whose generated DDL omits foreign-key constraints.')
                    ->scalarPrototype()->end()
                ->end()
            ->end();
    }

    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $builder->prependExtensionConfig('doctrine', [
            'dbal' => [
                'types' => TypeRegistrar::TYPES,
                'driver_schemes' => ['duckdb' => Driver::class],
            ],
        ]);
    }

    /** @param array{omit_foreign_keys: list<string>} $config */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $listener = $builder->register(self::JOINED_SUBCLASS_ROWS_LISTENER_SERVICE, JoinedSubclassRowsListener::class);

        foreach (JoinedSubclassRowsListener::EVENTS as $event) {
            $listener->addTag('doctrine.event_listener', ['event' => $event]);
        }

        $builder->register(self::LOG_SCHEMA_NOTICES_MIDDLEWARE_SERVICE, LogSchemaNotices::class)
            ->setArguments([new Reference('logger')])
            ->addTag('monolog.logger', ['channel' => 'doctrine'])
            ->addTag('doctrine.middleware');

        if ($config['omit_foreign_keys'] === []) {
            return;
        }

        $middleware = $builder->register(self::MIDDLEWARE_SERVICE, OmitForeignKeys::class)
            ->setAbstract(true);

        foreach ($config['omit_foreign_keys'] as $connection) {
            $middleware->addTag('doctrine.middleware', ['connection' => $connection]);
        }
    }
}
