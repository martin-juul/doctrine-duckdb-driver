<?php

declare(strict_types=1);

namespace MartinJuul\DBAL\DuckDB\Tests\Functional\Types;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use MartinJuul\DBAL\DuckDB\Platform\DuckDBPlatform;
use MartinJuul\DBAL\DuckDB\Schema\DuckDBSchemaManager;
use MartinJuul\DBAL\DuckDB\Tests\Functional\FunctionalTestCase;
use MartinJuul\DBAL\DuckDB\Types\TypeRegistrar;

/**
 * Creates a table `t` with an `id` column and a value column `v` of the type
 * under test, writes values through DBAL's type conversion and reads them
 * back the same way.
 */
abstract class TypeTestCase extends FunctionalTestCase
{
    protected Connection $connection;

    protected DuckDBPlatform $platform;

    protected DuckDBSchemaManager $schemaManager;

    private int $nextId = 1;

    protected function setUp(): void
    {
        TypeRegistrar::register();

        $this->connection = self::connect();

        $platform = $this->connection->getDatabasePlatform();
        self::assertInstanceOf(DuckDBPlatform::class, $platform);
        $this->platform = $platform;

        $schemaManager = $this->connection->createSchemaManager();
        self::assertInstanceOf(DuckDBSchemaManager::class, $schemaManager);
        $this->schemaManager = $schemaManager;
    }

    /**
     * A nullable value column `v` with the given platform options.
     *
     * @param array<string, mixed> $platformOptions
     */
    protected static function valueColumn(string $typeName, array $platformOptions = []): Column
    {
        return new Column('v', $typeName, ['notnull' => false, 'platformOptions' => $platformOptions]);
    }

    protected function createTable(Column $column): Table
    {
        $table = Table::editor()
            ->setUnquotedName('t')
            ->setColumns(
                Column::editor()->setUnquotedName('id')->setTypeName(Types::INTEGER)->create(),
                $column,
            )
            ->create();

        foreach ($this->platform->getCreateTableSQL($table) as $sql) {
            $this->connection->executeStatement($sql);
        }

        return $table;
    }

    /** Writes the value into a new row through the type, and reads it back through the type. */
    protected function roundTrip(mixed $value, string $typeName): mixed
    {
        $id = $this->nextId++;

        $this->connection->insert('t', ['id' => $id, 'v' => $value], ['v' => $typeName]);

        return $this->connection->convertToPHPValue(
            $this->connection->fetchOne('SELECT v FROM t WHERE id = ?', [$id]),
            $typeName,
        );
    }

    /** The type DuckDB reports for column `v`. */
    protected function storedType(): mixed
    {
        return $this->connection->fetchOne(
            "SELECT data_type FROM duckdb_columns() WHERE table_name = 't' AND column_name = 'v'",
        );
    }

    /**
     * Introspects table `t` through both DBAL introspection paths, checks that the value column has the declared
     * type and platform options, and that neither path reports a change against the declaration.
     *
     * @param array<string, mixed> $expectedOptions
     */
    protected function assertIntrospectsUnchanged(Table $declared, array $expectedOptions): void
    {
        $comparator = $this->schemaManager->createComparator();

        $legacy  = $this->schemaManager->introspectTable('t');
        $current = $this->schemaManager->introspectTableByUnquotedName('t');

        foreach ([$legacy, $current] as $table) {
            $column = $table->getColumn('v');

            self::assertSame($declared->getColumn('v')->getTypeName(), $column->getTypeName());

            foreach ($expectedOptions as $option => $value) {
                self::assertSame($value, $column->getPlatformOptions()[$option] ?? null, $option);
            }

            self::assertTrue($comparator->compareTables($declared, $table)->isEmpty());
            self::assertTrue($comparator->compareTables($table, $declared)->isEmpty());
        }
    }
}
