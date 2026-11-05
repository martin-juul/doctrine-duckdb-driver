<?php

declare(strict_types=1);

namespace MartinJuul\DBAL\DuckDB\Tests\Functional\Schema;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\ColumnEditor;
use Doctrine\DBAL\Schema\Name\UnqualifiedName;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Schema\TableDiff;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use MartinJuul\DBAL\DuckDB\Migrations\RebuildTables;
use MartinJuul\DBAL\DuckDB\Tests\Functional\FunctionalTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

use function array_map;

final class RenameColumnTest extends FunctionalTestCase
{
    /** @return iterable<array{non-empty-string, non-empty-string}> */
    public static function columnNames(): iterable
    {
        yield ['c1', 'c1_x'];
        yield ['C1', 'c1_x'];
        yield ['importantColumn', 'very_important_column'];
    }

    /**
     * @param non-empty-string $oldName
     * @param non-empty-string $newName
     */
    #[DataProvider('columnNames')]
    public function testRenamingAndRetypingAColumnInOneDiffKeepsItsPositionAndValues(
        string $oldName,
        string $newName,
    ): void {
        $connection = $this->seededConnection($oldName);
        $table      = self::table($oldName)->edit()
            ->renameColumn(UnqualifiedName::unquoted($oldName), UnqualifiedName::unquoted($newName))
            ->modifyColumnByUnquotedName($newName, static function (ColumnEditor $editor): void {
                $editor->setTypeName(Types::BIGINT);
            })
            ->create();

        $this->alterTable($connection, $table);

        self::assertColumnsAndValues($connection, $newName);
    }

    /**
     * Upstream: RenameColumnTest::testColumnPositionRetainedAfterExplicitRenaming,
     * which renames with the deprecated Table::renameColumn(). That leaves the
     * column's object name stale, so the rebuild must name the new column the
     * way CREATE TABLE does.
     *
     * @param non-empty-string $oldName
     * @param non-empty-string $newName
     */
    #[DataProvider('columnNames')]
    public function testTheDeprecatedRenameColumnApiRenamesAndRetypesInOneDiff(string $oldName, string $newName): void
    {
        $connection = $this->seededConnection($oldName);
        $table      = self::table($oldName);

        self::renameAndRetype($table, $oldName, $newName);

        $diff = $this->alterTable($connection, $table);

        self::assertCount(1, $diff->getRenamedColumns());
        self::assertCount(1, $diff->getModifiedColumns());
        self::assertColumnsAndValues($connection, $newName);
    }

    /**
     * The migration helper with the deprecated Table::renameColumn().
     *
     * @param non-empty-string $oldName
     * @param non-empty-string $newName
     */
    #[DataProvider('columnNames')]
    public function testRebuildTablesQueuesACopyToTheDeprecatedApisNewName(string $oldName, string $newName): void
    {
        $connection = $this->seededConnection($oldName);
        $statements = [];

        RebuildTables::queue(
            $connection,
            static function (Schema $current) use ($oldName, $newName): Schema {
                self::renameAndRetype($current->getTable('test_rename'), $oldName, $newName);

                return $current;
            },
            static function (string $sql) use (&$statements): void {
                $statements[] = $sql;
            },
        );

        $connection->transactional(static function (Connection $connection) use ($statements): void {
            foreach ($statements as $sql) {
                $connection->executeStatement($sql);
            }
        });

        // The rebuild creates the target table, in which Table::renameColumn() moved the column last.
        self::assertColumnsAndValues($connection, $newName, renamedLast: true);
    }

    /** @param non-empty-string $oldName */
    private function seededConnection(string $oldName): Connection
    {
        $connection = self::connect();

        $connection->createSchemaManager()->createTable(self::table($oldName));
        $connection->executeStatement('INSERT INTO test_rename VALUES (1, 10), (2, 20)');

        return $connection;
    }

    private function alterTable(Connection $connection, Table $table): TableDiff
    {
        $schemaManager = $connection->createSchemaManager();
        $diff          = $schemaManager->createComparator()
            ->compareTables($schemaManager->introspectTableByUnquotedName('test_rename'), $table);

        $schemaManager->alterTable($diff);

        return $diff;
    }

    /**
     * @param non-empty-string $oldName
     * @param non-empty-string $newName
     */
    private static function renameAndRetype(Table $table, string $oldName, string $newName): void
    {
        // Force a different type to make sure the rename is not detected implicitly.
        $table->renameColumn($oldName, $newName)
            ->setType(Type::getType(Types::BIGINT))
            ->setLength(32);
    }

    /** @param non-empty-string $newName */
    private static function assertColumnsAndValues(Connection $connection, string $newName, bool $renamedLast = false): void
    {
        $table = $connection->createSchemaManager()->introspectTableByUnquotedName('test_rename');

        self::assertSame($renamedLast ? ['c2', $newName] : [$newName, 'c2'], array_map(
            static fn (Column $column): string => $column->getObjectName()->getIdentifier()->getValue(),
            $table->getColumns(),
        ));
        self::assertSame(Types::BIGINT, $table->getColumn($newName)->getTypeName());
        self::assertSame(
            [[1, 10], [2, 20]],
            $connection->fetchAllNumeric('SELECT ' . $newName . ', c2 FROM test_rename ORDER BY 1'),
        );
    }

    /** @param non-empty-string $firstColumn */
    private static function table(string $firstColumn): Table
    {
        return Table::editor()
            ->setUnquotedName('test_rename')
            ->setColumns(
                Column::editor()->setUnquotedName($firstColumn)->setTypeName(Types::INTEGER)->create(),
                Column::editor()->setUnquotedName('c2')->setTypeName(Types::INTEGER)->create(),
            )
            ->create();
    }
}
