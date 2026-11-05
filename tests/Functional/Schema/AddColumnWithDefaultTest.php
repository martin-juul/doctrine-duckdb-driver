<?php

declare(strict_types=1);

namespace MartinJuul\DBAL\DuckDB\Tests\Functional\Schema;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\DefaultExpression\CurrentTimestamp;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use MartinJuul\DBAL\DuckDB\Tests\Functional\FunctionalTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

use function array_column;

final class AddColumnWithDefaultTest extends FunctionalTestCase
{
    /** @return iterable<string, array{string, bool, bool}> */
    public static function additions(): iterable
    {
        foreach ([Types::DATETIME_MUTABLE, Types::DATETIME_IMMUTABLE] as $type) {
            foreach (['empty' => false, 'populated' => true] as $state => $populated) {
                yield $type . ', ' . $state . ' table, alterTable()' => [$type, $populated, false];
                yield $type . ', ' . $state . ' table, migrateSchema() in a transaction' => [$type, $populated, true];
            }
        }
    }

    /**
     * Upstream: AddColumnWithDefaultTest::testAddColumnWithCurrentTimestampDefaultToPopulatedTable.
     * DuckDB refuses `ADD COLUMN ... DEFAULT CURRENT_TIMESTAMP NOT NULL` on a
     * table with rows, so the column must be added by a rebuild.
     */
    #[DataProvider('additions')]
    public function testAddingANotNullColumnWithACurrentTimestampDefault(
        string $type,
        bool $populated,
        bool $inTransaction,
    ): void {
        $connection    = self::connect();
        $schemaManager = $connection->createSchemaManager();

        $schemaManager->createTable(self::table());

        if ($populated) {
            $connection->insert('add_default_test', ['original_field' => 'one']);
        }

        $target = self::table(Column::editor()
            ->setUnquotedName('created_at')
            ->setTypeName($type)
            ->setDefaultValue(new CurrentTimestamp())
            ->create());

        if ($inTransaction) {
            $connection->transactional(static function (Connection $connection) use ($target): void {
                $connection->createSchemaManager()->migrateSchema(new Schema([$target]));
            });
        } else {
            $schemaManager->alterTable($schemaManager->createComparator()->compareTables(
                $schemaManager->introspectTableByUnquotedName('add_default_test'),
                $target,
            ));
        }

        $connection->insert('add_default_test', ['original_field' => 'two']);

        $rows = $connection->fetchAllAssociative(
            'SELECT original_field, created_at FROM add_default_test ORDER BY original_field',
        );

        self::assertSame($populated ? ['one', 'two'] : ['two'], array_column($rows, 'original_field'));

        foreach ($rows as $row) {
            self::assertIsString($row['created_at']);
            self::assertNotSame('', $row['created_at']);
        }

        $column = $schemaManager->introspectTableByUnquotedName('add_default_test')->getColumn('created_at');

        self::assertTrue($column->getNotnull());
        self::assertInstanceOf(CurrentTimestamp::class, $column->getDefault());
    }

    private static function table(Column|null $extra = null): Table
    {
        $columns = [
            Column::editor()
                ->setUnquotedName('original_field')
                ->setTypeName(Types::STRING)
                ->setLength(8)
                ->create(),
        ];

        if ($extra !== null) {
            $columns[] = $extra;
        }

        return Table::editor()
            ->setUnquotedName('add_default_test')
            ->setColumns(...$columns)
            ->create();
    }
}
