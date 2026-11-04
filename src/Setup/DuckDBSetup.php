<?php

declare(strict_types=1);

namespace MartinJuul\DBAL\DuckDB\Setup;

use Doctrine\DBAL\Configuration as DBALConfiguration;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\ORM\Configuration as ORMConfiguration;
use Doctrine\ORM\Mapping\ClassMetadata;
use MartinJuul\DBAL\DuckDB\Driver;
use MartinJuul\DBAL\DuckDB\Driver\Middleware\OmitForeignKeys;
use MartinJuul\DBAL\DuckDB\Platform\DuckDBPlatform;
use MartinJuul\DBAL\DuckDB\Types\TypeRegistrar;

use function array_merge;

/**
 * Applies the package's Doctrine configuration without Symfony. Every change
 * is scoped to DuckDB, so configurations shared with other databases keep
 * working for them.
 */
final class DuckDBSetup
{
    /** Registers the package's DBAL types (`duckdb_list`, `duckdb_struct`, …). */
    public static function registerTypes(): void
    {
        TypeRegistrar::register();
    }

    /**
     * Returns a DSN parser that maps the `duckdb` scheme to the driver. Use
     * `duckdb://localhost/<relative path>` or `duckdb://localhost//<absolute path>`.
     */
    public static function createDsnParser(): DsnParser
    {
        return new DsnParser(['duckdb' => Driver::class]);
    }

    /**
     * Registers the package's types and makes ORM's default (AUTO) identifier
     * strategy use sequences on DuckDB, keeping the preferences already set.
     */
    public static function configureOrm(ORMConfiguration $configuration): void
    {
        self::registerTypes();

        $configuration->setIdentityGenerationPreferences(array_merge(
            [DuckDBPlatform::class => ClassMetadata::GENERATOR_TYPE_SEQUENCE],
            $configuration->getIdentityGenerationPreferences(),
        ));
    }

    /** Omits foreign-key constraints from the DDL generated for DuckDB connections that use the configuration. */
    public static function omitForeignKeys(DBALConfiguration $configuration): void
    {
        $middlewares   = $configuration->getMiddlewares();
        $middlewares[] = new OmitForeignKeys();

        $configuration->setMiddlewares($middlewares);
    }
}
