<?php

declare(strict_types=1);

namespace MartinJuul\DBAL\DuckDB\Tests\Functional\Schema;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\ColumnEditor;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\SchemaDiff;
use Doctrine\DBAL\Schema\Table;
use MartinJuul\DBAL\DuckDB\Platform\DuckDBPlatform;
use MartinJuul\DBAL\DuckDB\Schema\DuckDBSchemaManager;
use MartinJuul\DBAL\DuckDB\Tests\Functional\FunctionalTestCase;

/**
 * A rebuild that turns a column holding values into an autoincrement column
 * starts the column's new owned sequence after the highest value, when the
 * rebuild runs.
 */
final class AutoincrementRebuildTest extends FunctionalTestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = self::connect();

        $this->connection->executeStatement('CREATE TABLE k (id INTEGER NOT NULL PRIMARY KEY, n INTEGER, v VARCHAR)');
    }

    public function testASchemaDiffContinuesAfterTheHighestCopiedValue(): void
    {
        $this->connection->executeStatement("INSERT INTO k VALUES (1, 7, 'a'), (5000, 7, 'b')");

        $this->schemaManager()->alterSchema($this->autoincrementDiff());

        $this->connection->executeStatement("INSERT INTO k (v) VALUES ('c'), ('d')");

        self::assertSame(
            [[1, 7, 'a'], [5000, 7, 'b'], [5001, 8, 'c'], [5002, 9, 'd']],
            $this->connection->fetchAllNumeric('SELECT id, n, v FROM k ORDER BY id'),
        );
    }

    public function testGeneratedSqlComputesTheStartWhenItRunsNotWhenItIsGenerated(): void
    {
        $this->connection->executeStatement("INSERT INTO k VALUES (1, 1, 'a')");

        $sql = $this->platform()->getAlterSchemaSQL($this->autoincrementDiff());

        // Rows added after the SQL was generated, as when a migration runs later.
        $this->connection->executeStatement("INSERT INTO k VALUES (40, 3, 'b')");

        foreach ($sql as $statement) {
            $this->connection->executeStatement($statement);
        }

        $this->connection->executeStatement("INSERT INTO k (v) VALUES ('c')");

        self::assertSame([41, 4], $this->connection->fetchNumeric("SELECT id, n FROM k WHERE v = 'c'"));
    }

    public function testATableDiffWithoutAConnectionContinuesAfterTheHighestValue(): void
    {
        $this->connection->executeStatement("INSERT INTO k VALUES (3, NULL, 'a')");

        $old = $this->schemaManager()->introspectTableByUnquotedName('k');

        foreach (
            $this->platform()->getAlterTableSQL($this->schemaManager()->createComparator()->compareTables(
                $old,
                self::withAutoincrement($old),
            )) as $sql
        ) {
            $this->connection->executeStatement($sql);
        }

        $this->connection->executeStatement("INSERT INTO k (v) VALUES ('b')");

        self::assertSame([4, 1], $this->connection->fetchNumeric("SELECT id, n FROM k WHERE v = 'b'"));
    }

    public function testAnEmptyTableStartsAtOne(): void
    {
        $this->schemaManager()->alterSchema($this->autoincrementDiff());

        $this->connection->executeStatement("INSERT INTO k (v) VALUES ('a')");

        self::assertSame([1, 1], $this->connection->fetchNumeric('SELECT id, n FROM k'));
    }

    public function testAColumnThatWasAutoincrementKeepsItsSequence(): void
    {
        $this->schemaManager()->alterSchema($this->autoincrementDiff());

        $this->connection->executeStatement("INSERT INTO k (v) VALUES ('a'), ('b')");
        $this->connection->executeStatement('DELETE FROM k');

        $current = $this->schemaManager()->introspectSchema();
        $target  = new Schema([$current->getTable('k')->edit()->renameColumnByUnquotedName('v', 'w')->create()]);

        $this->schemaManager()->alterSchema($this->schemaManager()->createComparator()->compareSchemas($current, $target));

        $this->connection->executeStatement("INSERT INTO k (w) VALUES ('c')");

        self::assertSame([3, 3], $this->connection->fetchNumeric('SELECT id, n FROM k'));
    }

    private function autoincrementDiff(): SchemaDiff
    {
        $current = $this->schemaManager()->introspectSchema();

        return $this->schemaManager()->createComparator()->compareSchemas(
            $current,
            new Schema([self::withAutoincrement($current->getTable('k'))]),
        );
    }

    /** The table with its id and n columns autoincrement. */
    private static function withAutoincrement(Table $table): Table
    {
        return $table->edit()
            ->modifyColumnByUnquotedName('id', static function (ColumnEditor $column): void {
                $column->setAutoincrement(true);
            })
            ->modifyColumnByUnquotedName('n', static function (ColumnEditor $column): void {
                $column->setAutoincrement(true);
            })
            ->create();
    }

    private function platform(): DuckDBPlatform
    {
        $platform = $this->connection->getDatabasePlatform();
        self::assertInstanceOf(DuckDBPlatform::class, $platform);

        return $platform;
    }

    private function schemaManager(): DuckDBSchemaManager
    {
        $schemaManager = $this->connection->createSchemaManager();
        self::assertInstanceOf(DuckDBSchemaManager::class, $schemaManager);

        return $schemaManager;
    }
}
