<?php

declare(strict_types=1);

namespace MartinJuul\DBAL\DuckDB\Tests\Functional\Platform;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\Exception\NotSupported;
use Doctrine\DBAL\Platforms\TrimMode;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\Index\IndexType;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Sequence;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Schema\UniqueConstraint;
use Doctrine\DBAL\TransactionIsolationLevel;
use Doctrine\DBAL\Types\Types;
use MartinJuul\DBAL\DuckDB\Platform\DuckDBPlatform;
use MartinJuul\DBAL\DuckDB\Tests\Functional\FunctionalTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Every statement the platform generates here runs on DuckDB 2.0.
 */
final class GeneratedSqlTest extends FunctionalTestCase
{
    private Connection $connection;

    private DuckDBPlatform $platform;

    protected function setUp(): void
    {
        $this->connection = self::connect();

        $platform = $this->connection->getDatabasePlatform();
        self::assertInstanceOf(DuckDBPlatform::class, $platform);

        $this->platform = $platform;
    }

    /** @return iterable<string, array{string, string}> */
    public static function dateArithmetic(): iterable
    {
        yield 'getDateAddSecondsExpression' => ['getDateAddSecondsExpression', '2024-01-31 10:00:02'];
        yield 'getDateSubSecondsExpression' => ['getDateSubSecondsExpression', '2024-01-31 09:59:58'];
        yield 'getDateAddMinutesExpression' => ['getDateAddMinutesExpression', '2024-01-31 10:02:00'];
        yield 'getDateSubMinutesExpression' => ['getDateSubMinutesExpression', '2024-01-31 09:58:00'];
        yield 'getDateAddHourExpression' => ['getDateAddHourExpression', '2024-01-31 12:00:00'];
        yield 'getDateSubHourExpression' => ['getDateSubHourExpression', '2024-01-31 08:00:00'];
        yield 'getDateAddDaysExpression' => ['getDateAddDaysExpression', '2024-02-02 10:00:00'];
        yield 'getDateSubDaysExpression' => ['getDateSubDaysExpression', '2024-01-29 10:00:00'];
        yield 'getDateAddWeeksExpression' => ['getDateAddWeeksExpression', '2024-02-14 10:00:00'];
        yield 'getDateSubWeeksExpression' => ['getDateSubWeeksExpression', '2024-01-17 10:00:00'];
        yield 'getDateAddMonthExpression' => ['getDateAddMonthExpression', '2024-03-31 10:00:00'];
        yield 'getDateSubMonthExpression' => ['getDateSubMonthExpression', '2023-11-30 10:00:00'];
        yield 'getDateAddQuartersExpression' => ['getDateAddQuartersExpression', '2024-07-31 10:00:00'];
        yield 'getDateSubQuartersExpression' => ['getDateSubQuartersExpression', '2023-07-31 10:00:00'];
        yield 'getDateAddYearsExpression' => ['getDateAddYearsExpression', '2026-01-31 10:00:00'];
        yield 'getDateSubYearsExpression' => ['getDateSubYearsExpression', '2022-01-31 10:00:00'];
    }

    #[DataProvider('dateArithmetic')]
    public function testDateArithmeticExecutes(string $method, string $expected): void
    {
        $expression = $this->platform->{$method}("TIMESTAMP '2024-01-31 10:00:00'", '2');

        self::assertIsString($expression);
        self::assertSame($expected, $this->connection->fetchOne('SELECT ' . $expression));
    }

    public function testDateArithmeticAcceptsAParameter(): void
    {
        $expression = $this->platform->getDateAddDaysExpression("DATE '2024-01-31'", '?');

        self::assertSame('2024-02-03 00:00:00', $this->connection->fetchOne('SELECT ' . $expression, [3]));
    }

    public function testDateDiffExecutes(): void
    {
        $expression = $this->platform->getDateDiffExpression(
            "TIMESTAMP '2024-03-01 01:00:00'",
            "TIMESTAMP '2024-02-01 23:00:00'",
        );

        self::assertSame(29, $this->connection->fetchOne('SELECT ' . $expression));
    }

    public function testStringAndNumericExpressionsExecute(): void
    {
        $platform = $this->platform;

        $row = $this->connection->fetchAssociative(
            'SELECT '
            . $platform->getLocateExpression("'hello world'", "'o'") . ' AS locate, '
            . $platform->getLocateExpression("'hello world'", "'o'", '6') . ' AS locate_from, '
            . $platform->getLocateExpression("'hello'", "'x'", '2') . ' AS locate_missing, '
            . $platform->getSubstringExpression("'hello'", '2', '3') . ' AS substring, '
            . $platform->getSubstringExpression("'hello'", '2') . ' AS substring_rest, '
            . $platform->getTrimExpression("'xxhix'", TrimMode::LEADING, "'x'") . ' AS trim_leading, '
            . $platform->getTrimExpression("'  a  '") . ' AS trim_both, '
            . $platform->getConcatExpression("'a'", "'b'", "'c'") . ' AS concat, '
            . $platform->getLengthExpression("'héllo'") . ' AS length, '
            . $platform->getModExpression('7', '3') . ' AS modulo, '
            . $platform->getBitAndComparisonExpression('6', '3') . ' AS bit_and, '
            . $platform->getBitOrComparisonExpression('6', '3') . ' AS bit_or, '
            . $platform->getCurrentDatabaseExpression() . ' AS current_db',
        );

        self::assertSame([
            'locate' => 5,
            'locate_from' => 8,
            'locate_missing' => 0,
            'substring' => 'ell',
            'substring_rest' => 'ello',
            'trim_leading' => 'hix',
            'trim_both' => 'a',
            'concat' => 'abc',
            'length' => 5,
            'modulo' => 1,
            'bit_and' => 2,
            'bit_or' => 7,
            'current_db' => 'memory',
        ], $row);
    }

    public function testCurrentDateAndTimeExpressionsExecute(): void
    {
        $sql = 'SELECT ' . $this->platform->getCurrentDateSQL()
            . ', ' . $this->platform->getCurrentTimeSQL()
            . ', ' . $this->platform->getCurrentTimestampSQL();

        self::assertCount(3, $this->connection->fetchNumeric($sql) ?: []);
    }

    public function testLimitAndOffsetExecute(): void
    {
        $query = 'SELECT range AS n FROM range(10) ORDER BY n';

        self::assertSame(
            [5, 6],
            $this->connection->fetchFirstColumn($this->platform->modifyLimitQuery($query, 2, 5)),
        );

        self::assertSame(
            [8, 9],
            $this->connection->fetchFirstColumn($this->platform->modifyLimitQuery($query, null, 8)),
        );
    }

    public function testSequenceLifecycleExecutes(): void
    {
        $sequence = Sequence::editor()
            ->setUnquotedName('order_seq')
            ->setAllocationSize(5)
            ->setInitialValue(10)
            ->setCacheSize(20)
            ->create();

        $this->connection->executeStatement($this->platform->getCreateSequenceSQL($sequence));

        self::assertSame(10, $this->connection->fetchOne($this->platform->getSequenceNextValSQL('order_seq')));
        self::assertSame(15, $this->connection->fetchOne($this->platform->getSequenceNextValSQL('order_seq')));

        $this->connection->executeStatement($this->platform->getDropSequenceSQL('order_seq'));

        self::assertSame(0, $this->connection->fetchOne(
            "SELECT count(*) FROM duckdb_sequences() WHERE sequence_name = 'order_seq'",
        ));
    }

    public function testEveryBuiltInTypeCreatesWithoutLengths(): void
    {
        $columns = [
            'string_col' => [Types::STRING, 255, false],
            'fixed_col' => [Types::STRING, 36, true],
            'ascii_col' => [Types::ASCII_STRING, 20, false],
            'text_col' => [Types::TEXT, null, false],
            'binary_col' => [Types::BINARY, 16, false],
            'blob_col' => [Types::BLOB, null, false],
            'guid_col' => [Types::GUID, null, false],
            'json_col' => [Types::JSON, null, false],
            'bool_col' => [Types::BOOLEAN, null, false],
            'smallint_col' => [Types::SMALLINT, null, false],
            'int_col' => [Types::INTEGER, null, false],
            'bigint_col' => [Types::BIGINT, null, false],
            'float_col' => [Types::FLOAT, null, false],
            'smallfloat_col' => [Types::SMALLFLOAT, null, false],
            'decimal_col' => [Types::DECIMAL, null, false],
            'date_col' => [Types::DATE_IMMUTABLE, null, false],
            'time_col' => [Types::TIME_IMMUTABLE, null, false],
            'datetime_col' => [Types::DATETIME_IMMUTABLE, null, false],
            'datetimetz_col' => [Types::DATETIMETZ_IMMUTABLE, null, false],
        ];

        $editor = Table::editor()->setUnquotedName('all_types');

        foreach ($columns as $name => [$type, $length, $fixed]) {
            $editor->addColumn(
                Column::editor()
                    ->setUnquotedName($name)
                    ->setTypeName($type)
                    ->setLength($length)
                    ->setFixed($fixed)
                    ->setPrecision(10)
                    ->setScale(2)
                    ->setNotNull(false)
                    ->create(),
            );
        }

        $this->executeAll($this->platform->getCreateTableSQL($editor->create()));

        $types = $this->connection->fetchAllKeyValue(
            "SELECT column_name, data_type FROM duckdb_columns() WHERE table_name = 'all_types'",
        );

        self::assertSame([
            'string_col' => 'VARCHAR',
            'fixed_col' => 'VARCHAR',
            'ascii_col' => 'VARCHAR',
            'text_col' => 'VARCHAR',
            'binary_col' => 'BLOB',
            'blob_col' => 'BLOB',
            'guid_col' => 'UUID',
            'json_col' => 'JSON',
            'bool_col' => 'BOOLEAN',
            'smallint_col' => 'SMALLINT',
            'int_col' => 'INTEGER',
            'bigint_col' => 'BIGINT',
            'float_col' => 'DOUBLE',
            'smallfloat_col' => 'FLOAT',
            'decimal_col' => 'DECIMAL(10,2)',
            'date_col' => 'DATE',
            'time_col' => 'TIME',
            'datetime_col' => 'TIMESTAMP_S',
            'datetimetz_col' => 'TIMESTAMP WITH TIME ZONE',
        ], $types);
    }

    public function testDefaultsExecute(): void
    {
        $table = Table::editor()
            ->setUnquotedName('defaults')
            ->setColumns(
                Column::editor()->setUnquotedName('id')->setTypeName(Types::INTEGER)->create(),
                Column::editor()->setUnquotedName('flag')->setTypeName(Types::BOOLEAN)->setDefaultValue(true)
                    ->create(),
                Column::editor()->setUnquotedName('label')->setTypeName(Types::STRING)->setDefaultValue("it's")
                    ->create(),
                Column::editor()->setUnquotedName('ratio')->setTypeName(Types::FLOAT)->setDefaultValue(1.5)
                    ->create(),
                Column::editor()->setUnquotedName('note')->setTypeName(Types::TEXT)->setNotNull(false)->create(),
            )
            ->create();

        $this->executeAll($this->platform->getCreateTableSQL($table));
        $this->connection->executeStatement('INSERT INTO defaults (id) VALUES (1)');

        self::assertSame(
            ['id' => 1, 'flag' => true, 'label' => "it's", 'ratio' => 1.5, 'note' => null],
            $this->connection->fetchAssociative('SELECT * FROM defaults'),
        );
    }

    public function testCommentsExecute(): void
    {
        $table = Table::editor()
            ->setUnquotedName('commented')
            ->setColumns(
                Column::editor()->setUnquotedName('id')->setTypeName(Types::INTEGER)->setComment("the 'id'")->create(),
            )
            ->setComment('A table')
            ->create();

        $this->executeAll($this->platform->getCreateTableSQL($table));

        self::assertSame('A table', $this->connection->fetchOne(
            "SELECT comment FROM duckdb_tables() WHERE table_name = 'commented'",
        ));

        self::assertSame("the 'id'", $this->connection->fetchOne(
            "SELECT comment FROM duckdb_columns() WHERE table_name = 'commented' AND column_name = 'id'",
        ));
    }

    public function testChildListedFirstStillCreates(): void
    {
        $this->executeAll($this->platform->getCreateTablesSQL([self::child(), self::parent()]));

        self::assertSame(
            ['child:FOREIGN KEY', 'child:PRIMARY KEY', 'parent:PRIMARY KEY'],
            $this->constraints(),
        );
    }

    public function testForeignKeyToANonPrimaryUniqueColumnCreates(): void
    {
        $parent = self::table(
            'parent',
            ['id', 'code'],
            ['id'],
            indexes: [
                Index::editor()
                    ->setUnquotedName('uniq_parent_code')
                    ->setType(IndexType::UNIQUE)
                    ->setUnquotedColumnNames('code')
                    ->create(),
            ],
        );

        $child = self::table(
            'child',
            ['id', 'parent_code'],
            ['id'],
            foreignKeys: [self::foreignKey('fk_child_parent', 'parent_code', 'parent', 'code')],
        );

        $this->executeAll($this->platform->getCreateTablesSQL([$child, $parent]));

        self::assertSame(
            ['child:FOREIGN KEY', 'child:PRIMARY KEY', 'parent:PRIMARY KEY', 'parent:UNIQUE'],
            $this->constraints(),
        );
    }

    public function testSelfReferencingTableCreates(): void
    {
        $category = self::table(
            'category',
            ['id', 'parent_id'],
            ['id'],
            foreignKeys: [self::foreignKey('fk_category_parent', 'parent_id', 'category', 'id')],
        );

        $this->executeAll($this->platform->getCreateTablesSQL([$category]));

        self::assertSame(['category:FOREIGN KEY', 'category:PRIMARY KEY'], $this->constraints());
    }

    public function testUnreferencedUniqueConstraintCreatesANamedUniqueIndex(): void
    {
        $table = self::table(
            'account',
            ['id', 'email'],
            ['id'],
            uniqueConstraints: [
                UniqueConstraint::editor()->setUnquotedName('uniq_account_email')->setUnquotedColumnNames('email')
                    ->create(),
            ],
        );

        $this->executeAll($this->platform->getCreateTableSQL($table));

        self::assertSame(
            [['uniq_account_email', true]],
            $this->connection->fetchAllNumeric(
                "SELECT index_name, is_unique FROM duckdb_indexes() WHERE table_name = 'account'",
            ),
        );
    }

    public function testSchemaToSqlAndToDropSqlExecute(): void
    {
        $grandchild = self::table(
            'grandchild',
            ['id', 'child_id'],
            ['id'],
            foreignKeys: [self::foreignKey('fk_grandchild_child', 'child_id', 'child', 'id')],
        );

        $schema = Schema::editor()
            ->setTables(self::parent(), self::child(), $grandchild)
            ->setSequences(Sequence::editor()->setUnquotedName('parent_id_seq')->create())
            ->create();

        $this->executeAll($schema->toSql($this->platform));

        self::assertSame(3, $this->connection->fetchOne('SELECT count(*) FROM duckdb_tables()'));
        self::assertSame(1, $this->connection->fetchOne('SELECT count(*) FROM duckdb_sequences()'));

        $this->executeAll($schema->toDropSql($this->platform));

        self::assertSame(0, $this->connection->fetchOne('SELECT count(*) FROM duckdb_tables()'));
        self::assertSame(0, $this->connection->fetchOne('SELECT count(*) FROM duckdb_sequences()'));
    }

    public function testSchemaQualifiedTableAndIndexExecute(): void
    {
        $table = Table::editor()
            ->setUnquotedName('events', 'reporting')
            ->setColumns(Column::editor()->setUnquotedName('id')->setTypeName(Types::INTEGER)->create())
            ->setIndexes(Index::editor()->setUnquotedName('idx_events_id')->setUnquotedColumnNames('id')->create())
            ->create();

        $this->connection->executeStatement($this->platform->getCreateSchemaSQL('reporting'));
        $this->executeAll($this->platform->getCreateTableSQL($table));
        $this->connection->executeStatement($this->platform->getDropIndexSQL('idx_events_id', 'reporting.events'));
        $this->connection->executeStatement($this->platform->getTruncateTableSQL('reporting.events'));
        $this->executeAll($this->platform->getDropTablesSQL([$table]));
        $this->connection->executeStatement($this->platform->getDropSchemaSQL('reporting'));

        self::assertSame(0, $this->connection->fetchOne(
            "SELECT count(*) FROM duckdb_schemas() WHERE schema_name = 'reporting'",
        ));
    }

    public function testQueryBuilderForUpdateIsRejected(): void
    {
        $queryBuilder = $this->connection->createQueryBuilder()
            ->select('n')
            ->from('range(3)', 't')
            ->forUpdate();

        $this->expectException(NotSupported::class);
        $this->expectExceptionMessageMatches('/DuckDB does not support row locks.*optimistic locking/s');

        $queryBuilder->executeQuery();
    }

    public function testSnapshotIsolationCanBeSelected(): void
    {
        $this->connection->setTransactionIsolation(TransactionIsolationLevel::REPEATABLE_READ);

        self::assertSame(
            TransactionIsolationLevel::REPEATABLE_READ,
            $this->connection->getTransactionIsolation(),
        );

        $this->expectException(NotSupported::class);
        $this->connection->setTransactionIsolation(TransactionIsolationLevel::READ_COMMITTED);
    }

    /** @param list<string> $statements */
    private function executeAll(array $statements): void
    {
        foreach ($statements as $statement) {
            $this->connection->executeStatement($statement);
        }
    }

    /** @return list<mixed> Each constraint other than NOT NULL as "table:type", sorted. */
    private function constraints(): array
    {
        return $this->connection->fetchFirstColumn(
            "SELECT table_name || ':' || constraint_type FROM duckdb_constraints()"
                . " WHERE constraint_type <> 'NOT NULL' ORDER BY 1",
        );
    }

    private static function parent(): Table
    {
        return self::table('parent', ['id'], ['id']);
    }

    private static function child(): Table
    {
        return self::table(
            'child',
            ['id', 'parent_id'],
            ['id'],
            foreignKeys: [self::foreignKey('fk_child_parent', 'parent_id', 'parent', 'id')],
        );
    }

    /**
     * @param non-empty-string                 $name
     * @param non-empty-list<non-empty-string> $columns
     * @param non-empty-list<non-empty-string> $primaryKey
     * @param list<Index>                      $indexes
     * @param list<UniqueConstraint>           $uniqueConstraints
     * @param list<ForeignKeyConstraint>       $foreignKeys
     */
    private static function table(
        string $name,
        array $columns,
        array $primaryKey,
        array $indexes = [],
        array $uniqueConstraints = [],
        array $foreignKeys = [],
    ): Table {
        $editor = Table::editor()->setUnquotedName($name);

        foreach ($columns as $column) {
            $editor->addColumn(Column::editor()->setUnquotedName($column)->setTypeName(Types::INTEGER)->create());
        }

        return $editor
            ->setPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames(...$primaryKey)->create())
            ->setIndexes(...$indexes)
            ->setUniqueConstraints(...$uniqueConstraints)
            ->setForeignKeyConstraints(...$foreignKeys)
            ->create();
    }

    /**
     * @param non-empty-string $name
     * @param non-empty-string $column
     * @param non-empty-string $referencedTable
     * @param non-empty-string $referencedColumn
     */
    private static function foreignKey(
        string $name,
        string $column,
        string $referencedTable,
        string $referencedColumn,
    ): ForeignKeyConstraint {
        return ForeignKeyConstraint::editor()
            ->setUnquotedName($name)
            ->setUnquotedReferencingColumnNames($column)
            ->setUnquotedReferencedTableName($referencedTable)
            ->setUnquotedReferencedColumnNames($referencedColumn)
            ->create();
    }
}
