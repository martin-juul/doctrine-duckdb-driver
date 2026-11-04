<?php

declare(strict_types=1);

namespace MartinJuul\DBAL\DuckDB\Tests\Functional\Schema;

use Closure;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\SchemaDiff;
use Doctrine\DBAL\Schema\Table;
use MartinJuul\DBAL\DuckDB\Migrations\RebuildTables;
use MartinJuul\DBAL\DuckDB\Platform\DuckDBPlatform;
use MartinJuul\DBAL\DuckDB\Schema\DuckDBComparator;
use MartinJuul\DBAL\DuckDB\Schema\DuckDBSchemaManager;
use MartinJuul\DBAL\DuckDB\Tests\Functional\FunctionalTestCase;

use function array_map;
use function is_string;

/**
 * A rebuild recreates the indexes on expressions that introspection skips,
 * and refuses a change that renames or drops a column such an index uses.
 */
final class ExpressionIndexRebuildTest extends FunctionalTestCase
{
    private const string EXPRESSION_INDEX = 'CREATE UNIQUE INDEX t_lower_w ON t((lower(w)));';

    private const string ORDINARY_INDEX = 'CREATE INDEX t_code ON t(code);';

    private const string FOREIGN_KEY_INDEX = 'CREATE INDEX t_p ON t(p_id);';

    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = self::connect();

        $this->connection->executeStatement('CREATE TABLE p (id INTEGER PRIMARY KEY, label VARCHAR)');
        $this->connection->executeStatement(
            'CREATE TABLE t (id INTEGER PRIMARY KEY, p_id INTEGER REFERENCES p (id), w VARCHAR, code VARCHAR, '
                . 'note VARCHAR)',
        );
        $this->connection->executeStatement('CREATE INDEX t_code ON t (code)');
        $this->connection->executeStatement('CREATE INDEX t_p ON t (p_id)');
        $this->connection->executeStatement('CREATE UNIQUE INDEX t_lower_w ON t (lower(w))');
        $this->connection->executeStatement("INSERT INTO p VALUES (1, 'one'), (2, 'two')");
        $this->connection->executeStatement("INSERT INTO t VALUES (1, 1, 'Alpha', 'a', 'x'), (2, 2, 'Beta', 'b', 'y')");

        self::assertSame([self::ORDINARY_INDEX, self::EXPRESSION_INDEX, self::FOREIGN_KEY_INDEX], $this->indexDefinitions());
    }

    public function testAColumnRenameAppliedAsGeneratedSqlKeepsTheExpressionIndex(): void
    {
        $diff = $this->diff(static fn (Table $t): Table => $t->edit()->renameColumnByUnquotedName('note', 'remark')->create());

        foreach ($this->platform()->getAlterSchemaSQL($diff) as $sql) {
            $this->connection->executeStatement($sql);
        }

        self::assertSame([self::ORDINARY_INDEX, self::EXPRESSION_INDEX, self::FOREIGN_KEY_INDEX], $this->indexDefinitions());
        self::assertSame(['x', 'y'], $this->connection->fetchFirstColumn('SELECT remark FROM t ORDER BY id'));
        $this->assertExpressionIndexWorks('t');
    }

    public function testAlterTableKeepsTheExpressionIndex(): void
    {
        $old = $this->schemaManager()->introspectTable('t');
        $new = $old->edit()->renameColumnByUnquotedName('note', 'remark')->create();

        $this->schemaManager()->alterTable($this->comparator()->compareTables($old, $new));

        self::assertSame([self::ORDINARY_INDEX, self::EXPRESSION_INDEX, self::FOREIGN_KEY_INDEX], $this->indexDefinitions());
        $this->assertExpressionIndexWorks('t');
    }

    public function testRenamingTheIndexedTableMovesTheExpressionIndexToTheNewName(): void
    {
        $this->schemaManager()->renameTable('t', 't2');

        self::assertSame(
            [
                'CREATE INDEX t_code ON t2(code);',
                'CREATE UNIQUE INDEX t_lower_w ON t2((lower(w)));',
                'CREATE INDEX t_p ON t2(p_id);',
            ],
            $this->indexDefinitions(),
        );
        $this->assertExpressionIndexWorks('t2');
    }

    public function testRebuildingTheReferencedTableKeepsTheReferencingTablesExpressionIndex(): void
    {
        $diff = $this->diff(static fn (Table $t): Table => $t, static fn (Table $p): Table => $p->edit()
            ->renameColumnByUnquotedName('label', 'name')
            ->create());

        $this->schemaManager()->alterSchema($diff);

        self::assertSame(['one', 'two'], $this->connection->fetchFirstColumn('SELECT name FROM p ORDER BY id'));
        self::assertSame([self::ORDINARY_INDEX, self::EXPRESSION_INDEX, self::FOREIGN_KEY_INDEX], $this->indexDefinitions());
        $this->assertExpressionIndexWorks('t');
    }

    public function testRebuildTablesQueueKeepsTheExpressionIndex(): void
    {
        $statements = [];

        RebuildTables::queue(
            $this->connection,
            fn (Schema $current): Schema => $this->target(
                $current,
                static fn (Table $t): Table => $t->edit()->renameColumnByUnquotedName('note', 'remark')->create(),
            ),
            static function (string $sql) use (&$statements): void {
                $statements[] = $sql;
            },
        );

        foreach ($statements as $sql) {
            $this->connection->executeStatement($sql);
        }

        self::assertSame([self::ORDINARY_INDEX, self::EXPRESSION_INDEX, self::FOREIGN_KEY_INDEX], $this->indexDefinitions());
        $this->assertExpressionIndexWorks('t');
    }

    public function testRenamingTheColumnAnExpressionIndexUsesFailsNamingTheIndexAndChangesNothing(): void
    {
        $before = $this->snapshot();
        $diff   = $this->diff(static fn (Table $t): Table => $t->edit()->renameColumnByUnquotedName('w', 'w2')->create());

        try {
            foreach ($this->platform()->getAlterSchemaSQL($diff) as $sql) {
                $this->connection->executeStatement($sql);
            }

            self::fail('The rebuild succeeded.');
        } catch (DriverException $e) {
            self::assertStringContainsString(
                'expression index t_lower_w uses column w, which this change renames or drops',
                $e->getMessage(),
            );
        }

        self::assertSame($before, $this->snapshot());
        $this->assertExpressionIndexWorks('t');
    }

    public function testDroppingTheColumnAnExpressionIndexUsesFailsNamingTheIndexAndChangesNothing(): void
    {
        $before = $this->snapshot();
        $old    = $this->schemaManager()->introspectTable('t');
        $new    = $old->edit()->dropColumnByUnquotedName('w')->create();

        try {
            $this->schemaManager()->alterTable($this->comparator()->compareTables($old, $new));

            self::fail('The rebuild succeeded.');
        } catch (DriverException $e) {
            self::assertStringContainsString(
                'expression index t_lower_w uses column w, which this change renames or drops',
                $e->getMessage(),
            );
        }

        self::assertFalse($this->connection->isTransactionActive());
        self::assertSame($before, $this->snapshot());
        $this->assertExpressionIndexWorks('t');
    }

    /** A lookup by the expression finds its row, and the unique expression index rejects a duplicate. */
    private function assertExpressionIndexWorks(string $table): void
    {
        self::assertSame(
            [2],
            $this->connection->fetchFirstColumn('SELECT id FROM ' . $table . " WHERE lower(w) = 'beta'"),
        );

        try {
            $this->connection->executeStatement('INSERT INTO ' . $table . " (id, p_id, w) VALUES (3, 1, 'ALPHA')");

            self::fail('The expression index accepted a duplicate.');
        } catch (UniqueConstraintViolationException) {
            self::assertSame(2, $this->connection->fetchOne('SELECT count(*) FROM ' . $table));
        }
    }

    /**
     * The CREATE INDEX statements DuckDB reports.
     *
     * @return list<string>
     */
    private function indexDefinitions(): array
    {
        return array_map(
            static fn (mixed $sql): string => is_string($sql) ? $sql : '',
            $this->connection->fetchFirstColumn('SELECT sql FROM duckdb_indexes() ORDER BY table_name, index_name'),
        );
    }

    /** @return array{columns: list<mixed>, rows: list<array<string, mixed>>, indexes: list<string>} */
    private function snapshot(): array
    {
        return [
            'columns' => $this->connection->fetchFirstColumn(
                "SELECT column_name FROM duckdb_columns() WHERE table_name = 't' ORDER BY column_index",
            ),
            'rows' => $this->connection->fetchAllAssociative('SELECT * FROM t ORDER BY id'),
            'indexes' => $this->indexDefinitions(),
        ];
    }

    /**
     * @param Closure(Table): Table $changeT
     * @param Closure(Table): Table $changeP
     */
    private function diff(Closure $changeT, Closure|null $changeP = null): SchemaDiff
    {
        $current = $this->schemaManager()->introspectSchema();

        return $this->comparator()->compareSchemas($current, $this->target($current, $changeT, $changeP));
    }

    /**
     * @param Closure(Table): Table $changeT
     * @param Closure(Table): Table $changeP
     */
    private function target(Schema $current, Closure $changeT, Closure|null $changeP = null): Schema
    {
        $changeP ??= static fn (Table $p): Table => $p;

        return new Schema([$changeP($current->getTable('p')), $changeT($current->getTable('t'))]);
    }

    private function comparator(): DuckDBComparator
    {
        return $this->schemaManager()->createComparator();
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
