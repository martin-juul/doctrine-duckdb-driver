<?php

declare(strict_types=1);

namespace MartinJuul\DBAL\DuckDB\Tests\Functional\Schema;

use Closure;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Logging\Middleware as LoggingMiddleware;
use Doctrine\DBAL\Platforms\Exception\NotSupported;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\ComparatorConfig;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\Index\IndexType;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\SchemaDiff;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use MartinJuul\DBAL\DuckDB\Driver;
use MartinJuul\DBAL\DuckDB\Driver\Middleware\OmitForeignKeys;
use MartinJuul\DBAL\DuckDB\Driver\StatementClassifier;
use MartinJuul\DBAL\DuckDB\Platform\DuckDBPlatform;
use MartinJuul\DBAL\DuckDB\Schema\DuckDBSchemaManager;
use MartinJuul\DBAL\DuckDB\Tests\Functional\FunctionalTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\AbstractLogger;
use Stringable;

use function array_filter;
use function array_key_last;
use function array_map;
use function array_values;
use function is_string;
use function str_contains;
use function str_starts_with;

final class RebuildTest extends FunctionalTestCase
{
    private Connection $connection;

    private string|null $path = null;

    /** @var list<string> */
    private array $statements = [];

    /** @return iterable<string, array{bool}> */
    public static function storages(): iterable
    {
        yield 'in-memory' => [false];
        yield 'file reopened after the rebuild' => [true];
    }

    /** Covers AE5. */
    #[DataProvider('storages')]
    public function testRenamingAnIndexedColumnOfAReferencedTableRebuildsBothTables(bool $file): void
    {
        $this->open($file);
        $this->create(self::alpha(), self::beta());
        $this->seed();

        $alpha = RebuildChecks::data($this->connection, 'a', 'id', ['id', 'v', 'code']);
        $beta  = RebuildChecks::data($this->connection, 'b', 'id', ['id', 'a_id', 'note']);
        $maxA  = RebuildChecks::maximumId($this->connection, 'a');
        $maxB  = RebuildChecks::maximumId($this->connection, 'b');

        $target = new Schema([self::alpha('w'), self::beta()]);
        $sql    = $this->applyGenerated($this->diff($target));

        self::assertSame(StatementClassifier::REBUILD_BEGIN, $sql[0]);
        self::assertSame(StatementClassifier::REBUILD_END, $sql[array_key_last($sql)]);

        $this->reopen();

        self::assertSame($alpha['rows'], RebuildChecks::data($this->connection, 'a', 'id', ['id', 'w', 'code'])['rows']);
        self::assertSame(
            array_values($alpha['columns']),
            array_values(RebuildChecks::data($this->connection, 'a', 'id', ['id', 'w', 'code'])['columns']),
        );
        self::assertSame($beta, RebuildChecks::data($this->connection, 'b', 'id', ['id', 'a_id', 'note']));
        self::assertSame(['b(a_id) -> a(id)'], RebuildChecks::foreignKeys($this->connection));
        self::assertSame(
            ['a.idx_a_value [w]', 'a.uniq_a_code UNIQUE [code]', 'b.idx_b_a [a_id]'],
            RebuildChecks::indexes($this->connection),
        );
        self::assertSame(['a: Alpha', 'a.w: Value', 'b.note: Note'], RebuildChecks::comments($this->connection));
        RebuildChecks::assertNoStagingTables($this->connection);
        RebuildChecks::assertForeignKeyRejects($this->connection, 'INSERT INTO b (a_id) VALUES (999)');
        RebuildChecks::assertNextIdExceeds($this->connection, "INSERT INTO a (w, code) VALUES ('new', 'n') RETURNING id", $maxA);
        RebuildChecks::assertNextIdExceeds($this->connection, 'INSERT INTO b (a_id) VALUES (1) RETURNING id', $maxB);
        $this->assertIntrospectionMatches($target);
    }

    #[DataProvider('storages')]
    public function testChangingTheRootOfAThreeLevelChainRebuildsAllThree(bool $file): void
    {
        $this->open($file);
        $this->create(self::alpha(), self::beta(), self::gamma());
        $this->seed();
        $this->seedGamma();

        $beta  = RebuildChecks::data($this->connection, 'b', 'id', ['id', 'a_id', 'note']);
        $gamma = RebuildChecks::data($this->connection, 'c', 'id', ['id', 'b_id', 'qty']);
        $maxC  = RebuildChecks::maximumId($this->connection, 'c');

        $target = new Schema([self::alpha('w'), self::beta(), self::gamma()]);

        $this->applyGenerated($this->diff($target));
        $this->reopen();

        self::assertSame($beta, RebuildChecks::data($this->connection, 'b', 'id', ['id', 'a_id', 'note']));
        self::assertSame($gamma, RebuildChecks::data($this->connection, 'c', 'id', ['id', 'b_id', 'qty']));
        self::assertSame(['b(a_id) -> a(id)', 'c(b_id) -> b(id)'], RebuildChecks::foreignKeys($this->connection));
        self::assertSame(
            ['a.idx_a_value [w]', 'a.uniq_a_code UNIQUE [code]', 'b.idx_b_a [a_id]', 'c.IDX_6B9DF6F296BFCB6 [b_id]'],
            RebuildChecks::indexes($this->connection),
        );
        self::assertSame(
            ['a: Alpha', 'a.w: Value', 'b.note: Note', 'c.qty: Quantity'],
            RebuildChecks::comments($this->connection),
        );
        RebuildChecks::assertNoStagingTables($this->connection);
        RebuildChecks::assertForeignKeyRejects($this->connection, 'INSERT INTO c (b_id, qty) VALUES (999, 1)');
        RebuildChecks::assertNextIdExceeds($this->connection, 'INSERT INTO c (qty) VALUES (2) RETURNING id', $maxC);
        $this->assertIntrospectionMatches($target);
    }

    #[DataProvider('storages')]
    public function testRenamingReorderingDroppingAndAddingColumnsMovesEveryValueToItsColumn(bool $file): void
    {
        $this->open($file);

        $old = Table::editor()
            ->setUnquotedName('m')
            ->setColumns(
                self::id(),
                Column::editor()->setUnquotedName('p')->setTypeName(Types::INTEGER)->create(),
                Column::editor()->setUnquotedName('q')->setTypeName(Types::STRING)->create(),
                Column::editor()->setUnquotedName('r')->setTypeName(Types::FLOAT)->create(),
                Column::editor()->setUnquotedName('s')->setTypeName(Types::STRING)->create(),
            )
            ->setPrimaryKeyConstraint(self::primaryKey())
            ->create();

        $this->create($old);
        $this->connection->executeStatement(
            "INSERT INTO m (p, q, r, s) VALUES (1, 'q1', 1.5, 's1'), (2, 'q2', 2.5, 's2'), (3, 'q3', 3.5, 's3')",
        );

        $before = RebuildChecks::data($this->connection, 'm', 'id', ['id', 'p', 'q', 's']);

        // p is renamed to p2, r is dropped, t is added, and s now comes before q: a positional copy would swap them.
        $new = Table::editor()
            ->setUnquotedName('m')
            ->setColumns(
                self::id(),
                Column::editor()->setUnquotedName('s')->setTypeName(Types::STRING)->create(),
                Column::editor()->setUnquotedName('p2')->setTypeName(Types::INTEGER)->create(),
                Column::editor()->setUnquotedName('q')->setTypeName(Types::STRING)->create(),
                Column::editor()->setUnquotedName('t')->setTypeName(Types::STRING)->setDefaultValue('fresh')->create(),
            )
            ->setPrimaryKeyConstraint(self::primaryKey())
            ->create();

        $target = new Schema([$new]);

        $this->applyGenerated($this->diff($target));
        $this->reopen();

        self::assertSame(
            array_values($before['columns']),
            array_values(RebuildChecks::data($this->connection, 'm', 'id', ['id', 'p2', 'q', 's'])['columns']),
        );
        self::assertSame(['fresh'], $this->connection->fetchFirstColumn('SELECT DISTINCT t FROM m'));
        self::assertSame(
            ['id', 's', 'p2', 'q', 't'],
            $this->connection->fetchFirstColumn(
                "SELECT column_name FROM duckdb_columns() WHERE table_name = 'm' ORDER BY column_index",
            ),
        );
        RebuildChecks::assertNoStagingTables($this->connection);
        $this->assertIntrospectionMatches($target);
    }

    #[DataProvider('storages')]
    public function testAddingANullableColumnToAnIndexedTableEmitsOnlyANativeAlter(bool $file): void
    {
        $this->open($file);
        $this->create(self::alpha(), self::beta());
        $this->seed();

        $alpha      = RebuildChecks::data($this->connection, 'a', 'id', ['id', 'v', 'code']);
        $alphaTable = Table::editor()
            ->setUnquotedName('a')
            ->setColumns(
                ...[
                    ...self::alpha()->getColumns(),
                    Column::editor()->setUnquotedName('extra')->setTypeName(Types::STRING)->setNotNull(false)->create(),
                ],
            )
            ->setPrimaryKeyConstraint(self::primaryKey())
            ->setIndexes(...array_values(array_filter(
                self::alpha()->getIndexes(),
                static fn (Index $index): bool => ! $index->isPrimary(),
            )))
            ->setComment('Alpha')
            ->create();

        $target = new Schema([$alphaTable, self::beta()]);

        self::assertSame(['ALTER TABLE "a" ADD COLUMN "extra" VARCHAR DEFAULT NULL'], $this->applyGenerated($this->diff($target)));

        $this->reopen();

        self::assertSame($alpha, RebuildChecks::data($this->connection, 'a', 'id', ['id', 'v', 'code']));
        $this->assertIntrospectionMatches($target);
    }

    #[DataProvider('storages')]
    public function testDroppingAColumnFromATableThatOwnsAForeignKeyRebuildsIt(bool $file): void
    {
        $this->open($file);
        $this->create(self::alpha(), self::beta());
        $this->seed();

        $beta   = RebuildChecks::data($this->connection, 'b', 'id', ['id', 'a_id']);
        $target = new Schema([self::alpha(), self::beta(withNote: false)]);
        $sql    = $this->applyGenerated($this->diff($target));

        self::assertSame([], array_values(array_filter($sql, static fn (string $s): bool => str_contains($s, 'DROP COLUMN'))));
        self::assertContains(StatementClassifier::REBUILD_BEGIN, $sql);

        $this->reopen();

        self::assertSame($beta, RebuildChecks::data($this->connection, 'b', 'id', ['id', 'a_id']));
        $this->assertIntrospectionMatches($target);

        // A later rename of the referenced table must keep inserts into the child working.
        $this->schemaManager()->renameTable('a', 'a_renamed');
        $this->reopen();

        self::assertSame(['b(a_id) -> a_renamed(id)'], RebuildChecks::foreignKeys($this->connection));
        $this->connection->executeStatement('INSERT INTO b (a_id) VALUES (2)');
        RebuildChecks::assertForeignKeyRejects($this->connection, 'INSERT INTO b (a_id) VALUES (999)');
    }

    #[DataProvider('storages')]
    public function testRenamingAnIndexedOrReferencedTableRebuildsItWithItsReferencingTables(bool $file): void
    {
        $this->open($file);
        $this->create(self::alpha(), self::beta());
        $this->seed();

        $alpha = RebuildChecks::data($this->connection, 'a', 'id', ['id', 'v', 'code']);
        $beta  = RebuildChecks::data($this->connection, 'b', 'id', ['id', 'a_id', 'note']);
        $maxA  = RebuildChecks::maximumId($this->connection, 'a');

        $this->schemaManager()->renameTable('a', 'alpha');
        $this->reopen();

        self::assertSame(['alpha', 'b'], $this->schemaManager()->listTableNames());
        self::assertSame($alpha, RebuildChecks::data($this->connection, 'alpha', 'id', ['id', 'v', 'code']));
        self::assertSame($beta, RebuildChecks::data($this->connection, 'b', 'id', ['id', 'a_id', 'note']));
        self::assertSame(['b(a_id) -> alpha(id)'], RebuildChecks::foreignKeys($this->connection));
        self::assertSame(
            ['alpha.idx_a_value [v]', 'alpha.uniq_a_code UNIQUE [code]', 'b.idx_b_a [a_id]'],
            RebuildChecks::indexes($this->connection),
        );
        self::assertSame(['alpha: Alpha', 'alpha.v: Value', 'b.note: Note'], RebuildChecks::comments($this->connection));
        RebuildChecks::assertNoStagingTables($this->connection);
        RebuildChecks::assertForeignKeyRejects($this->connection, 'INSERT INTO b (a_id) VALUES (999)');
        RebuildChecks::assertNextIdExceeds(
            $this->connection,
            "INSERT INTO alpha (v, code) VALUES ('new', 'n') RETURNING id",
            $maxA,
        );

        // DuckDB cannot rename sequences, so the owned sequence keeps the old table's name.
        self::assertSame(
            ['a_id' . DuckDBPlatform::OWNED_SEQUENCE_SUFFIX, 'b_id' . DuckDBPlatform::OWNED_SEQUENCE_SUFFIX],
            $this->connection->fetchFirstColumn('SELECT sequence_name FROM duckdb_sequences() ORDER BY 1'),
        );
    }

    public function testRenamingAnUnindexedUnreferencedTableRenamesItNatively(): void
    {
        $this->connection = $this->recordingConnection();
        $this->create(self::plain());
        $this->connection->executeStatement("INSERT INTO plain (label) VALUES ('one'), ('two')");

        $this->statements = [];
        $this->schemaManager()->renameTable('plain', 'renamed');

        self::assertSame(['ALTER TABLE plain RENAME TO renamed'], $this->executedDdl());
        self::assertSame(['one', 'two'], $this->connection->fetchFirstColumn('SELECT label FROM renamed ORDER BY id'));
        RebuildChecks::assertNextIdExceeds($this->connection, "INSERT INTO renamed (label) VALUES ('x') RETURNING id", 2);
    }

    #[DataProvider('storages')]
    public function testAddingAForeignKeyRebuildsTheTable(bool $file): void
    {
        $this->open($file);
        $this->create(self::alpha(), self::beta(withForeignKey: false));
        $this->seed();

        $beta   = RebuildChecks::data($this->connection, 'b', 'id', ['id', 'a_id', 'note']);
        $target = new Schema([self::alpha(), self::beta()]);

        $this->applyGenerated($this->diff($target));
        $this->reopen();

        self::assertSame($beta, RebuildChecks::data($this->connection, 'b', 'id', ['id', 'a_id', 'note']));
        self::assertSame(['b(a_id) -> a(id)'], RebuildChecks::foreignKeys($this->connection));
        RebuildChecks::assertForeignKeyRejects($this->connection, 'INSERT INTO b (a_id) VALUES (999)');
        $this->assertIntrospectionMatches($target);
    }

    #[DataProvider('storages')]
    public function testAForeignKeyToAColumnWithOnlyAUniqueIndexRebuildsTheParentWithAUniqueConstraint(bool $file): void
    {
        $this->open($file);
        // DBAL names the unique index as introspection names the UNIQUE constraint that replaces it.
        $this->create(self::alpha(uniqueName: null), self::beta());
        $this->seed();

        $alpha = RebuildChecks::data($this->connection, 'a', 'id', ['id', 'v', 'code']);

        $coded  = Table::editor()
            ->setUnquotedName('coded')
            ->setColumns(
                self::id(),
                Column::editor()->setUnquotedName('a_code')->setTypeName(Types::STRING)->create(),
            )
            ->setPrimaryKeyConstraint(self::primaryKey())
            ->addForeignKeyConstraint(
                ForeignKeyConstraint::editor()
                    ->setUnquotedReferencingColumnNames('a_code')
                    ->setUnquotedReferencedTableName('a')
                    ->setUnquotedReferencedColumnNames('code')
                    ->create(),
            )
            ->create();
        $target = new Schema([self::alpha(uniqueName: null), self::beta(), $coded]);

        self::assertSame(
            ['a.UNIQ_E8B7BE4377153098 UNIQUE [code]', 'a.idx_a_value [v]', 'b.idx_b_a [a_id]'],
            RebuildChecks::indexes($this->connection),
        );

        $this->applyGenerated($this->diff($target));
        $this->reopen();

        self::assertSame($alpha, RebuildChecks::data($this->connection, 'a', 'id', ['id', 'v', 'code']));
        self::assertSame(
            ['b(a_id) -> a(id)', 'coded(a_code) -> a(code)'],
            RebuildChecks::foreignKeys($this->connection),
        );
        self::assertSame(
            ['a.idx_a_value [v]', 'b.idx_b_a [a_id]', 'coded.IDX_667E448A9E78CAC2 [a_code]', 'a UNIQUE (code)'],
            RebuildChecks::indexes($this->connection),
        );

        $this->connection->executeStatement("INSERT INTO coded (a_code) VALUES ('c1')");
        RebuildChecks::assertForeignKeyRejects($this->connection, "INSERT INTO coded (a_code) VALUES ('nope')");
        $this->assertIntrospectionMatches($target);
    }

    #[DataProvider('storages')]
    public function testDroppingACheckConstraintRebuildsTheTableAndKeepsTheOtherConstraints(bool $file): void
    {
        $this->open($file);

        $declared = self::checked(withMinimum: true);

        $this->create($declared);
        $this->connection->executeStatement('INSERT INTO checked (qty, price) VALUES (1, 10), (2, 20)');

        $before = RebuildChecks::data($this->connection, 'checked', 'id', ['id', 'qty', 'price']);

        self::assertSame(
            [
                'CHECK((price <= 100))',
                'CHECK((qty >= 0))',
                'NOT NULL',
                'NOT NULL',
                'NOT NULL',
                'id DEFAULT '
                . "nextval('\"checked_id" . DuckDBPlatform::OWNED_SEQUENCE_SUFFIX . "\"')",
                'price DEFAULT 5',
            ],
            RebuildChecks::constraints($this->connection, 'checked'),
        );

        $sql = $this->applyGenerated($this->diff(new Schema([self::checked(withMinimum: false)]), new Schema([$declared])));

        self::assertContains(StatementClassifier::REBUILD_BEGIN, $sql);

        $this->reopen();

        self::assertSame($before, RebuildChecks::data($this->connection, 'checked', 'id', ['id', 'qty', 'price']));
        self::assertSame(
            [
                'CHECK((price <= 100))',
                'NOT NULL',
                'NOT NULL',
                'NOT NULL',
                'id DEFAULT '
                . "nextval('\"checked_id" . DuckDBPlatform::OWNED_SEQUENCE_SUFFIX . "\"')",
                'price DEFAULT 5',
            ],
            RebuildChecks::constraints($this->connection, 'checked'),
        );

        $this->connection->executeStatement('INSERT INTO checked (qty) VALUES (-1)');

        try {
            $this->connection->executeStatement('INSERT INTO checked (qty, price) VALUES (1, 101)');
            self::fail('The remaining CHECK constraint accepted a price above 100.');
        } catch (DriverException) {
        }
    }

    #[DataProvider('storages')]
    public function testWideAndNestedValuesCopyExactly(bool $file): void
    {
        $this->open($file);

        $columns = ['id', 'd', 'h', 'm', 's', 'arr', 'tz', 'iv', 'bl', 'u', 'label'];

        $this->connection->executeStatement(<<<'SQL'
            CREATE TABLE wide (
                id INTEGER PRIMARY KEY,
                d DECIMAL(38,10),
                h HUGEINT,
                m MAP(VARCHAR, INTEGER),
                s STRUCT(a INTEGER, b VARCHAR),
                arr INTEGER[3],
                tz TIMESTAMPTZ,
                iv INTERVAL,
                bl BLOB,
                u UTINYINT,
                label VARCHAR
            )
            SQL);
        $this->connection->executeStatement('CREATE INDEX idx_wide_label ON wide (label)');
        $this->connection->executeStatement(<<<'SQL'
            INSERT INTO wide VALUES
                (1, 1234567890123456789012345678.0123456789, 170141183460469231731687303715884105727,
                 MAP {'x': 1, 'y': 2}, {'a': 1, 'b': 'b'}, [1, 2, 3], '2026-11-04 12:34:56.123456+02',
                 INTERVAL '1 year 2 months 3 days 04:05:06.789', '\x00\xFF\x10'::BLOB, 255, 'one'),
                (2, -0.0000000001, -170141183460469231731687303715884105728, MAP {}, {'a': NULL, 'b': NULL},
                 [NULL, 0, -1], 'infinity', INTERVAL '-1 microsecond', ''::BLOB, 0, NULL),
                (3, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'three')
            SQL);

        $types  = $this->columnTypes('wide');
        $before = RebuildChecks::data($this->connection, 'wide', 'id', $columns);

        $old    = $this->schemaManager()->introspectTableByUnquotedName('wide');
        $target = new Schema([
            $old->edit()->renameColumnByUnquotedName('label', 'title')->create(),
        ]);

        $this->applyGenerated($this->diff($target));
        $this->reopen();

        $columns[10] = 'title';

        self::assertSame(
            array_values($before['columns']),
            array_values(RebuildChecks::data($this->connection, 'wide', 'id', $columns)['columns']),
        );
        self::assertSame(['label' => 'VARCHAR'] + $types, ['label' => 'VARCHAR'] + $this->columnTypes('wide', 'title'));
        self::assertSame(['wide.idx_wide_label [title]'], RebuildChecks::indexes($this->connection));
        RebuildChecks::assertNoStagingTables($this->connection);
    }

    public function testATableWithAGeneratedColumnIsRefusedNamingTheColumn(): void
    {
        $this->open(false);

        $this->connection->executeStatement(
            'CREATE TABLE gen (id INTEGER PRIMARY KEY, a INTEGER, doubled INTEGER GENERATED ALWAYS AS (a * 2), '
                . 'label VARCHAR)',
        );
        $this->connection->executeStatement('CREATE INDEX idx_gen_label ON gen (label)');

        $old    = $this->schemaManager()->introspectTableByUnquotedName('gen');
        $target = new Schema([$old->edit()->renameColumnByUnquotedName('label', 'title')->create()]);

        $this->expectException(NotSupported::class);
        $this->expectExceptionMessage('gen.doubled');

        $this->diff($target);
    }

    #[DataProvider('storages')]
    public function testANewTableReferencingARebuiltTableIsCreatedAfterTheRebuild(bool $file): void
    {
        $this->open($file);
        $this->create(self::alpha(), self::beta());
        $this->seed();

        $delta  = Table::editor()
            ->setUnquotedName('d')
            ->setColumns(self::id(), Column::editor()->setUnquotedName('a_id')->setTypeName(Types::INTEGER)->create())
            ->setPrimaryKeyConstraint(self::primaryKey())
            ->addForeignKeyConstraint(
                ForeignKeyConstraint::editor()
                    ->setUnquotedReferencingColumnNames('a_id')
                    ->setUnquotedReferencedTableName('a')
                    ->setUnquotedReferencedColumnNames('id')
                    ->create(),
            )
            ->create();
        $target = new Schema([self::alpha('w'), self::beta(), $delta]);

        $this->applyGenerated($this->diff($target));
        $this->reopen();

        self::assertSame(['b(a_id) -> a(id)', 'd(a_id) -> a(id)'], RebuildChecks::foreignKeys($this->connection));
        $this->connection->executeStatement('INSERT INTO d (a_id) VALUES (1)');
        RebuildChecks::assertForeignKeyRejects($this->connection, 'INSERT INTO d (a_id) VALUES (999)');
        $this->assertIntrospectionMatches($target);
    }

    #[DataProvider('storages')]
    public function testATableStillReferencedUntilARebuildIsDroppedAfterItsReferencingTable(bool $file): void
    {
        $this->open($file);

        $extra = Table::editor()
            ->setUnquotedName('x')
            ->setColumns(self::id())
            ->setPrimaryKeyConstraint(self::primaryKey())
            ->create();
        $withX = Table::editor()
            ->setUnquotedName('y')
            ->setColumns(
                self::id(),
                Column::editor()->setUnquotedName('x_id')->setTypeName(Types::INTEGER)->setNotNull(false)->create(),
            )
            ->setPrimaryKeyConstraint(self::primaryKey())
            ->addForeignKeyConstraint(
                ForeignKeyConstraint::editor()
                    ->setUnquotedReferencingColumnNames('x_id')
                    ->setUnquotedReferencedTableName('x')
                    ->setUnquotedReferencedColumnNames('id')
                    ->create(),
            )
            ->create();

        $this->create($extra, $withX);
        $this->connection->executeStatement('INSERT INTO x DEFAULT VALUES');
        $this->connection->executeStatement('INSERT INTO y (x_id) VALUES (1), (NULL)');

        $before   = RebuildChecks::data($this->connection, 'y', 'id', ['id', 'x_id']);
        $withoutX = Table::editor()
            ->setUnquotedName('y')
            ->setColumns(
                self::id(),
                Column::editor()->setUnquotedName('x_id')->setTypeName(Types::INTEGER)->setNotNull(false)->create(),
            )
            ->setPrimaryKeyConstraint(self::primaryKey())
            ->create();
        $target   = new Schema([$withoutX]);

        $this->applyGenerated($this->diff($target));
        $this->reopen();

        self::assertSame(['y'], $this->schemaManager()->listTableNames());
        self::assertSame($before, RebuildChecks::data($this->connection, 'y', 'id', ['id', 'x_id']));
        self::assertSame([], RebuildChecks::foreignKeys($this->connection));
        $this->assertIntrospectionMatches($target);
    }

    public function testAnAssetFilterHidingAReferencingTableFailsAtTheDropAndRollsBack(): void
    {
        $this->open(false);
        $this->create(self::alpha(), self::beta());
        $this->seed();

        $alpha = RebuildChecks::data($this->connection, 'a', 'id', ['id', 'v', 'code']);
        $beta  = RebuildChecks::data($this->connection, 'b', 'id', ['id', 'a_id', 'note']);

        $this->connection->getConfiguration()->setSchemaAssetsFilter(static fn (string $name): bool => $name !== 'b');

        $diff = $this->diff(new Schema([self::alpha('w')]));

        try {
            $this->applyGenerated($diff);
            self::fail('The rebuild dropped a table that a hidden table references.');
        } catch (DriverException) {
        }

        $this->connection->getConfiguration()->setSchemaAssetsFilter(static fn (): bool => true);

        self::assertSame($alpha, RebuildChecks::data($this->connection, 'a', 'id', ['id', 'v', 'code']));
        self::assertSame($beta, RebuildChecks::data($this->connection, 'b', 'id', ['id', 'a_id', 'note']));
        self::assertSame(['a', 'b'], $this->schemaManager()->listTableNames());
        RebuildChecks::assertNoStagingTables($this->connection);
    }

    /** Covers AE16. */
    #[DataProvider('storages')]
    public function testAFailureAfterTheDropsWithoutATransactionLeavesTablesRowsAndSequencesIntact(bool $file): void
    {
        $this->open($file);
        $this->create(self::alpha(), self::beta());
        $this->seed();

        $alpha = RebuildChecks::data($this->connection, 'a', 'id', ['id', 'v', 'code']);
        $beta  = RebuildChecks::data($this->connection, 'b', 'id', ['id', 'a_id', 'note']);
        $maxA  = RebuildChecks::maximumId($this->connection, 'a');

        // The values of a.v cannot become integers, so the copy into the new table fails after the drops.
        $target = new Schema([self::alpha(valueType: Types::INTEGER), self::beta()]);
        $sql    = $this->platform()->getAlterSchemaSQL($this->diff($target));

        self::assertFalse($this->connection->isTransactionActive());

        try {
            foreach ($sql as $statement) {
                $this->connection->executeStatement($statement);
            }

            self::fail('The rebuild converted text to integers.');
        } catch (DriverException $e) {
            self::assertStringContainsString('Could not convert', $e->getMessage());
        }

        $this->reopen();

        self::assertSame($alpha, RebuildChecks::data($this->connection, 'a', 'id', ['id', 'v', 'code']));
        self::assertSame($beta, RebuildChecks::data($this->connection, 'b', 'id', ['id', 'a_id', 'note']));
        self::assertSame(['b(a_id) -> a(id)'], RebuildChecks::foreignKeys($this->connection));
        self::assertSame(
            ['a.idx_a_value [v]', 'a.uniq_a_code UNIQUE [code]', 'b.idx_b_a [a_id]'],
            RebuildChecks::indexes($this->connection),
        );
        RebuildChecks::assertNoStagingTables($this->connection);

        // The rolled-back rebuild leaves the owned sequence usable, and IDs keep increasing.
        $first = $this->connection->fetchOne("INSERT INTO a (v, code) VALUES ('after', 'af') RETURNING id");
        self::assertGreaterThan($maxA, $first);
        RebuildChecks::assertNextIdExceeds(
            $this->connection,
            "INSERT INTO a (v, code) VALUES ('later', 'la') RETURNING id",
            RebuildChecks::maximumId($this->connection, 'a'),
        );
    }

    #[DataProvider('storages')]
    public function testTheSchemaManagerRebuildsAtRunTime(bool $file): void
    {
        $this->open($file);
        $this->create(self::alpha(), self::beta());
        $this->seed();

        $beta   = RebuildChecks::data($this->connection, 'b', 'id', ['id', 'a_id', 'note']);
        $target = new Schema([self::alpha('w'), self::beta()]);

        $this->schemaManager()->migrateSchema($target);
        $this->reopen();

        self::assertSame($beta, RebuildChecks::data($this->connection, 'b', 'id', ['id', 'a_id', 'note']));
        self::assertSame(['b(a_id) -> a(id)'], RebuildChecks::foreignKeys($this->connection));
        RebuildChecks::assertNoStagingTables($this->connection);
        $this->assertIntrospectionMatches($target);
        self::assertFalse($this->connection->isTransactionActive());
    }

    public function testWithForeignKeysOmittedDiffsNeverProposeThemAndRenamesRebuildOnlyTheTable(): void
    {
        $this->connection = DriverManager::getConnection(
            ['driverClass' => Driver::class, 'memory' => true],
            (new Configuration())->setMiddlewares([new OmitForeignKeys()]),
        );

        $this->create(self::alpha(), self::beta(withForeignKey: false));
        $this->seed();

        $beta = RebuildChecks::data($this->connection, 'b', 'id', ['id', 'a_id', 'note']);

        self::assertSame([], $this->diff(new Schema([self::alpha(), self::beta()]))->getAlteredTables());

        $sql = $this->applyGenerated($this->diff(new Schema([self::alpha('w'), self::beta()])));

        self::assertSame([], array_values(array_filter(
            $sql,
            static fn (string $statement): bool => str_contains($statement, '"b"'),
        )));
        self::assertSame($beta, RebuildChecks::data($this->connection, 'b', 'id', ['id', 'a_id', 'note']));
        self::assertSame([], RebuildChecks::foreignKeys($this->connection));
        RebuildChecks::assertNoStagingTables($this->connection);
    }

    public function testSwitchingAColumnToAutoincrementIsADiff(): void
    {
        $this->open(false);

        $this->create(self::plain(autoincrement: false));

        $diff = $this->diff(new Schema([self::plain()]));

        self::assertCount(1, $diff->getAlteredTables());
        self::assertContains(StatementClassifier::REBUILD_BEGIN, $this->platform()->getAlterSchemaSQL($diff));
    }

    private function open(bool $file): void
    {
        if (! $file) {
            $this->connection = self::connect();

            return;
        }

        $this->path       = $this->temporaryDatabasePath();
        $this->connection = self::connect(['path' => $this->path]);
    }

    /** Reopens a file database, so the checks read what DuckDB wrote to it. */
    private function reopen(): void
    {
        if ($this->path === null) {
            return;
        }

        $this->connection->close();
        $this->connection = self::connect(['path' => $this->path]);
    }

    /** A connection that records the SQL of every statement it runs in {@see $statements}. */
    private function recordingConnection(): Connection
    {
        $record = function (string $sql): void {
            $this->statements[] = $sql;
        };

        $logger = new class ($record) extends AbstractLogger {
            /** @param Closure(string): void $record */
            public function __construct(private readonly Closure $record)
            {
            }

            /** @param array<array-key, mixed> $context */
            public function log(mixed $level, string|Stringable $message, array $context = []): void
            {
                $sql = $context['sql'] ?? null;

                if (! is_string($sql)) {
                    return;
                }

                ($this->record)($sql);
            }
        };

        $configuration = (new Configuration())->setMiddlewares([new LoggingMiddleware($logger)]);

        return DriverManager::getConnection(['driverClass' => Driver::class, 'memory' => true], $configuration);
    }

    /**
     * The recorded statements other than queries.
     *
     * @return list<string>
     */
    private function executedDdl(): array
    {
        return array_values(array_filter(
            $this->statements,
            static fn (string $sql): bool => ! str_starts_with($sql, 'SELECT') && ! str_starts_with($sql, 'WITH'),
        ));
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

    private function create(Table ...$tables): void
    {
        foreach ($this->platform()->getCreateTablesSQL($tables) as $sql) {
            $this->connection->executeStatement($sql);
        }
    }

    private function diff(Schema $target, Schema|null $from = null): SchemaDiff
    {
        $comparator = $this->schemaManager()->createComparator(
            (new ComparatorConfig())->withReportModifiedIndexes(false),
        );

        return $comparator->compareSchemas($from ?? $this->schemaManager()->introspectSchema(), $target);
    }

    /**
     * Runs the generated statements one by one without a transaction, as `schema:update --force` does.
     *
     * @return list<string>
     */
    private function applyGenerated(SchemaDiff $diff): array
    {
        $sql = $this->platform()->getAlterSchemaSQL($diff);

        foreach ($sql as $statement) {
            $this->connection->executeStatement($statement);
        }

        return $sql;
    }

    private function assertIntrospectionMatches(Schema $target): void
    {
        $diff = $this->schemaManager()
            ->createComparator((new ComparatorConfig())->withReportModifiedIndexes(false))
            ->compareSchemas($target, $this->schemaManager()->introspectSchema());

        self::assertSame([], array_map(
            static fn ($tableDiff): string => $tableDiff->getOldTable()->getName(),
            $diff->getAlteredTables(),
        ));
        self::assertSame([], $diff->getCreatedTables());
        self::assertSame([], $diff->getDroppedTables());
    }

    /** @return array<string, string> */
    private function columnTypes(string $table, string $skip = 'label'): array
    {
        $types = [];

        $rows = $this->connection->fetchAllNumeric(
            'SELECT column_name, data_type FROM duckdb_columns() WHERE table_name = ? ORDER BY column_index',
            [$table],
        );

        foreach ($rows as [$name, $type]) {
            if ($name === $skip || ! is_string($name) || ! is_string($type)) {
                continue;
            }

            $types[$name] = $type;
        }

        return $types;
    }

    private function seed(): void
    {
        $this->connection->executeStatement(
            "INSERT INTO a (v, code) VALUES ('alpha', 'c1'), ('beta', 'c2'), ('gamma', 'c3')",
        );
        $this->connection->executeStatement(
            "INSERT INTO b (a_id, note) VALUES (1, 'one'), (1, NULL), (3, 'three'), (2, 'two')",
        );
    }

    private function seedGamma(): void
    {
        $this->connection->executeStatement(
            'INSERT INTO c (b_id, qty) VALUES (1, 1.5), (NULL, 12345678901234567890.0123456789), (4, 0)',
        );
    }

    private static function id(): Column
    {
        return Column::editor()->setUnquotedName('id')->setTypeName(Types::INTEGER)->setAutoincrement(true)->create();
    }

    private static function primaryKey(): PrimaryKeyConstraint
    {
        return PrimaryKeyConstraint::editor()->setUnquotedColumnNames('id')->create();
    }

    /**
     * @param non-empty-string  $valueColumn
     * @param ?non-empty-string $uniqueName
     */
    private static function alpha(
        string $valueColumn = 'v',
        string $valueType = Types::STRING,
        string|null $uniqueName = 'uniq_a_code',
    ): Table {
        $unique = Index::editor()->setType(IndexType::UNIQUE)->setUnquotedColumnNames('code');

        if ($uniqueName !== null) {
            $unique->setUnquotedName($uniqueName);
        }

        return Table::editor()
            ->setUnquotedName('a')
            ->setColumns(
                self::id(),
                Column::editor()
                    ->setUnquotedName($valueColumn)
                    ->setTypeName($valueType)
                    ->setComment('Value')
                    ->create(),
                Column::editor()->setUnquotedName('code')->setTypeName(Types::STRING)->create(),
            )
            ->setPrimaryKeyConstraint(self::primaryKey())
            ->setIndexes(
                Index::editor()->setUnquotedName('idx_a_value')->setUnquotedColumnNames($valueColumn)->create(),
                $unique,
            )
            ->setComment('Alpha')
            ->create();
    }

    private static function beta(bool $withNote = true, bool $withForeignKey = true): Table
    {
        $columns = [
            self::id(),
            Column::editor()->setUnquotedName('a_id')->setTypeName(Types::INTEGER)->create(),
        ];

        if ($withNote) {
            $columns[] = Column::editor()
                ->setUnquotedName('note')
                ->setTypeName(Types::STRING)
                ->setNotNull(false)
                ->setComment('Note')
                ->create();
        }

        $editor = Table::editor()
            ->setUnquotedName('b')
            ->setColumns(...$columns)
            ->setPrimaryKeyConstraint(self::primaryKey())
            ->setIndexes(Index::editor()->setUnquotedName('idx_b_a')->setUnquotedColumnNames('a_id')->create());

        if ($withForeignKey) {
            $editor->addForeignKeyConstraint(
                ForeignKeyConstraint::editor()
                    ->setUnquotedName('fk_b_a')
                    ->setUnquotedReferencingColumnNames('a_id')
                    ->setUnquotedReferencedTableName('a')
                    ->setUnquotedReferencedColumnNames('id')
                    ->create(),
            );
        }

        return $editor->create();
    }

    private static function gamma(): Table
    {
        return Table::editor()
            ->setUnquotedName('c')
            ->setColumns(
                self::id(),
                Column::editor()->setUnquotedName('b_id')->setTypeName(Types::INTEGER)->setNotNull(false)->create(),
                Column::editor()
                    ->setUnquotedName('qty')
                    ->setTypeName(Types::DECIMAL)
                    ->setPrecision(38)
                    ->setScale(10)
                    ->setComment('Quantity')
                    ->create(),
            )
            ->setPrimaryKeyConstraint(self::primaryKey())
            ->addForeignKeyConstraint(
                ForeignKeyConstraint::editor()
                    ->setUnquotedName('fk_c_b')
                    ->setUnquotedReferencingColumnNames('b_id')
                    ->setUnquotedReferencedTableName('b')
                    ->setUnquotedReferencedColumnNames('id')
                    ->create(),
            )
            ->create();
    }

    private static function plain(bool $autoincrement = true): Table
    {
        return Table::editor()
            ->setUnquotedName('plain')
            ->setColumns(
                Column::editor()
                    ->setUnquotedName('id')
                    ->setTypeName(Types::INTEGER)
                    ->setAutoincrement($autoincrement)
                    ->create(),
                Column::editor()->setUnquotedName('label')->setTypeName(Types::STRING)->create(),
            )
            ->setPrimaryKeyConstraint(self::primaryKey())
            ->create();
    }

    private static function checked(bool $withMinimum): Table
    {
        $quantity = Column::editor()->setUnquotedName('qty')->setTypeName(Types::INTEGER);

        if ($withMinimum) {
            $quantity->setMinimumValue(0);
        }

        return Table::editor()
            ->setUnquotedName('checked')
            ->setColumns(
                self::id(),
                $quantity->create(),
                Column::editor()
                    ->setUnquotedName('price')
                    ->setTypeName(Types::INTEGER)
                    ->setDefaultValue(5)
                    ->setMaximumValue(100)
                    ->create(),
            )
            ->setPrimaryKeyConstraint(self::primaryKey())
            ->create();
    }
}
