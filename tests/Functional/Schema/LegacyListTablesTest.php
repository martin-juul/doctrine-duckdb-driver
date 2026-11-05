<?php

declare(strict_types=1);

namespace MartinJuul\DBAL\DuckDB\Tests\Functional\Schema;

use Doctrine\DBAL\Schema\Column;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\Driver\DatabaseDriver;
use MartinJuul\DBAL\DuckDB\Tests\Functional\FunctionalTestCase;
use stdClass;

use function array_keys;
use function array_map;

final class LegacyListTablesTest extends FunctionalTestCase
{
    /**
     * The legacy listing names columns as other platforms do: unquoted unless
     * the name needs quotes, the same way as the primary key columns.
     */
    public function testListedColumnsAreNamedLikeThePrimaryKeyColumns(): void
    {
        $connection = self::connect();

        $connection->executeStatement(
            'CREATE TABLE dbdriver_foo (id INTEGER PRIMARY KEY, bar VARCHAR(200), "two words" INTEGER)',
        );

        $schemaManager = $connection->createSchemaManager();
        $tables        = $schemaManager->listTables();

        self::assertCount(1, $tables);

        $table = $tables[0];

        self::assertSame(['id', 'bar', '"two words"'], array_map(
            static fn (Column $column): string => $column->getObjectName()->toString(),
            $table->getColumns(),
        ));
        self::assertSame(['id'], array_map(
            static fn ($name): string => $name->toString(),
            $table->getPrimaryKeyConstraint()?->getColumnNames() ?? [],
        ));
        self::assertSame(['id', 'bar', '"two words"'], array_keys($schemaManager->listTableColumns('dbdriver_foo')));
    }

    /** Upstream: ORM DatabaseDriverTest::testLoadMetadataFromDatabase. */
    public function testTheOrmDatabaseDriverMapsTheColumnsAsFields(): void
    {
        $connection = self::connect();

        $connection->executeStatement('CREATE TABLE dbdriver_foo (id INTEGER PRIMARY KEY, bar VARCHAR(200))');

        $driver = new DatabaseDriver($connection->createSchemaManager());

        // The driver needs no class; an existing one keeps the class names typed.
        $driver->setClassNameForTable('dbdriver_foo', stdClass::class);

        self::assertSame([stdClass::class], $driver->getAllClassNames());

        $metadata = new ClassMetadata(stdClass::class);

        $driver->loadMetadataForClass(stdClass::class, $metadata);

        self::assertSame(['id', 'bar'], array_keys($metadata->fieldMappings));
        self::assertSame('id', $metadata->fieldMappings['id']->columnName);
        self::assertSame(true, $metadata->fieldMappings['id']->id);
        self::assertSame('bar', $metadata->fieldMappings['bar']->columnName);
        self::assertSame(['id'], $metadata->identifier);
    }
}
