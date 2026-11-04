<?php

declare(strict_types=1);

namespace MartinJuul\DBAL\DuckDB\Tests\Functional\Migrations;

use Closure;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\ComparatorConfig;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use MartinJuul\DBAL\DuckDB\Driver\StatementClassifier;
use MartinJuul\DBAL\DuckDB\Migrations\RebuildTables;
use MartinJuul\DBAL\DuckDB\Platform\DuckDBPlatform;
use MartinJuul\DBAL\DuckDB\Tests\Functional\FunctionalTestCase;
use MartinJuul\DBAL\DuckDB\Tests\Functional\Schema\RebuildChecks;
use PHPUnit\Framework\Attributes\DataProvider;

use function array_filter;
use function array_values;
use function count;
use function str_starts_with;

final class RebuildMigrationTest extends FunctionalTestCase
{
    private Connection $connection;

    private string|null $path = null;

    /** @return iterable<string, array{bool}> */
    public static function storages(): iterable
    {
        yield 'in-memory' => [false];
        yield 'file reopened after the rebuild' => [true];
    }

    #[DataProvider('storages')]
    public function testAGeneratedRebuildRunsInsideTheMigrationTransaction(bool $file): void
    {
        $this->open($file);
        $this->createParentAndChild();

        $beta = RebuildChecks::data($this->connection, 'b', 'id', ['id', 'a_id']);
        $sql  = $this->generatedSql(new Schema([self::alpha('w'), self::beta()]));

        $runner = (new MigrationRunner($this->connection))->add(self::queueAll($sql));

        $runner->migrate();
        $this->reopen();

        self::assertSame(['Version001'], (new MigrationRunner($this->connection))->executedVersions());
        self::assertSame(['alpha', 'beta', 'gamma'], $this->connection->fetchFirstColumn('SELECT w FROM a ORDER BY id'));
        self::assertSame($beta, RebuildChecks::data($this->connection, 'b', 'id', ['id', 'a_id']));
        self::assertSame(['b(a_id) -> a(id)'], RebuildChecks::foreignKeys($this->connection));
        RebuildChecks::assertNoStagingTables($this->connection);
        RebuildChecks::assertForeignKeyRejects($this->connection, 'INSERT INTO b (a_id) VALUES (999)');
    }

    /** Covers AE6. */
    #[DataProvider('storages')]
    public function testARebuildFailingAfterTheCopyLeavesBothTablesAndRowsUnchanged(bool $file): void
    {
        $this->open($file);
        $this->createParentAndChild();

        $alpha   = RebuildChecks::data($this->connection, 'a', 'id', ['id', 'v']);
        $beta    = RebuildChecks::data($this->connection, 'b', 'id', ['id', 'a_id']);
        $indexes = RebuildChecks::indexes($this->connection);

        // The failure is forced right after the rows are copied back into the new tables.
        $sql    = [];
        $copied = false;

        foreach ($this->generatedSql(new Schema([self::alpha('w'), self::beta()])) as $statement) {
            if ($copied && ! str_starts_with($statement, 'INSERT INTO')) {
                $sql[]  = "SELECT error('forced failure after the copy')";
                $copied = false;
            }

            $sql[] = $statement;

            if (! str_starts_with($statement, 'INSERT INTO')) {
                continue;
            }

            $copied = true;
        }

        self::assertContains("SELECT error('forced failure after the copy')", $sql);

        $runner = (new MigrationRunner($this->connection))->add(self::queueAll($sql));

        try {
            $runner->migrate();
            self::fail('The forced failure did not stop the migration.');
        } catch (DriverException $e) {
            self::assertStringContainsString('forced failure after the copy', $e->getMessage());
        }

        $this->reopen();

        self::assertFalse($this->connection->isTransactionActive());
        self::assertSame([], (new MigrationRunner($this->connection))->executedVersions());
        self::assertSame($alpha, RebuildChecks::data($this->connection, 'a', 'id', ['id', 'v']));
        self::assertSame($beta, RebuildChecks::data($this->connection, 'b', 'id', ['id', 'a_id']));
        self::assertSame($indexes, RebuildChecks::indexes($this->connection));
        self::assertSame(['b(a_id) -> a(id)'], RebuildChecks::foreignKeys($this->connection));
        RebuildChecks::assertNoStagingTables($this->connection);
    }

    /** Covers AE17. */
    #[DataProvider('storages')]
    public function testTheHelperRebuildsASelfReferencingTableFromAMigration(bool $file): void
    {
        $this->open($file);
        $this->createTree();

        $before = RebuildChecks::data($this->connection, 'category', 'id', ['id', 'parent_id', 'name']);
        $runner = (new MigrationRunner($this->connection))->add(self::renameCategoryLabel());

        $runner->migrate();
        $this->reopen();

        $after = RebuildChecks::data($this->connection, 'category', 'id', ['id', 'parent_id', 'title']);

        self::assertSame(['Version001'], (new MigrationRunner($this->connection))->executedVersions());
        self::assertSame($before['rows'], $after['rows']);
        self::assertSame(array_values($before['columns']), array_values($after['columns']));
        self::assertSame(['category(parent_id) -> category(id)'], RebuildChecks::foreignKeys($this->connection));
        RebuildChecks::assertNoStagingTables($this->connection);
        RebuildChecks::assertForeignKeyRejects(
            $this->connection,
            "INSERT INTO category (parent_id, title) VALUES (999, 'orphan')",
        );
        RebuildChecks::assertNextIdExceeds(
            $this->connection,
            "INSERT INTO category (parent_id, title) VALUES (1, 'new') RETURNING id",
            RebuildChecks::maximumId($this->connection, 'category'),
        );
    }

    public function testTheHelperUnderDryRunPrintsTheStatementsAndChangesNothing(): void
    {
        $this->open(false);
        $this->createTree();

        $before = RebuildChecks::data($this->connection, 'category', 'id', ['id', 'parent_id', 'name']);
        $runner = (new MigrationRunner($this->connection))->add(self::renameCategoryLabel());

        $sql = $runner->migrate(dryRun: true)['Version001'];

        self::assertSame(StatementClassifier::REBUILD_BEGIN, $sql[0]);
        self::assertContains('DROP TABLE "category"', $sql);

        // A three-level tree is copied with one statement per level.
        self::assertCount(3, array_filter(
            $sql,
            static fn (string $statement): bool => str_starts_with($statement, 'INSERT INTO "category"'),
        ));

        self::assertSame($before, RebuildChecks::data($this->connection, 'category', 'id', ['id', 'parent_id', 'name']));
        self::assertSame([], $runner->executedVersions());
        RebuildChecks::assertNoStagingTables($this->connection);
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

    private function reopen(): void
    {
        if ($this->path === null) {
            return;
        }

        $this->connection->close();
        $this->connection = self::connect(['path' => $this->path]);
    }

    private function platform(): DuckDBPlatform
    {
        $platform = $this->connection->getDatabasePlatform();
        self::assertInstanceOf(DuckDBPlatform::class, $platform);

        return $platform;
    }

    /**
     * The SQL migrations:diff writes into a migration for the change.
     *
     * @return list<string>
     */
    private function generatedSql(Schema $target): array
    {
        $schemaManager = $this->connection->createSchemaManager();
        $diff          = $schemaManager
            ->createComparator((new ComparatorConfig())->withReportModifiedIndexes(false))
            ->compareSchemas($schemaManager->introspectSchema(), $target);

        $sql = $this->platform()->getAlterSchemaSQL($diff);

        self::assertGreaterThan(2, count($sql));
        self::assertSame(StatementClassifier::REBUILD_BEGIN, $sql[0]);

        return $sql;
    }

    private function createParentAndChild(): void
    {
        foreach ($this->platform()->getCreateTablesSQL([self::alpha('v'), self::beta()]) as $sql) {
            $this->connection->executeStatement($sql);
        }

        $this->connection->executeStatement("INSERT INTO a (v) VALUES ('alpha'), ('beta'), ('gamma')");
        $this->connection->executeStatement('INSERT INTO b (a_id) VALUES (1), (1), (3), (2)');
    }

    private function createTree(): void
    {
        foreach ($this->platform()->getCreateTablesSQL([self::category('name')]) as $sql) {
            $this->connection->executeStatement($sql);
        }

        $this->connection->executeStatement("INSERT INTO category (parent_id, name) VALUES (NULL, 'root')");
        $this->connection->executeStatement("INSERT INTO category (parent_id, name) VALUES (1, 'child'), (1, 'child 2')");
        $this->connection->executeStatement("INSERT INTO category (parent_id, name) VALUES (2, 'grandchild'), (3, 'gc 2')");
    }

    /**
     * @param list<string> $sql
     *
     * @return Closure(Connection, Closure(string): void): void
     */
    private static function queueAll(array $sql): Closure
    {
        return static function (Connection $connection, Closure $addSql) use ($sql): void {
            foreach ($sql as $statement) {
                $addSql($statement);
            }
        };
    }

    /** @return Closure(Connection, Closure(string): void): void */
    private static function renameCategoryLabel(): Closure
    {
        return static function (Connection $connection, Closure $addSql): void {
            RebuildTables::queue(
                $connection,
                static fn (Schema $current): Schema => $current->edit()
                    ->dropTableByUnquotedName('category')
                    ->addTable(self::category('title'))
                    ->create(),
                $addSql,
            );
        };
    }

    /** @param non-empty-string $valueColumn */
    private static function alpha(string $valueColumn): Table
    {
        return Table::editor()
            ->setUnquotedName('a')
            ->setColumns(
                Column::editor()->setUnquotedName('id')->setTypeName(Types::INTEGER)->setAutoincrement(true)->create(),
                Column::editor()->setUnquotedName($valueColumn)->setTypeName(Types::STRING)->create(),
            )
            ->setPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('id')->create())
            ->addIndex(Index::editor()->setUnquotedName('idx_a_value')->setUnquotedColumnNames($valueColumn)->create())
            ->create();
    }

    private static function beta(): Table
    {
        return Table::editor()
            ->setUnquotedName('b')
            ->setColumns(
                Column::editor()->setUnquotedName('id')->setTypeName(Types::INTEGER)->setAutoincrement(true)->create(),
                Column::editor()->setUnquotedName('a_id')->setTypeName(Types::INTEGER)->create(),
            )
            ->setPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('id')->create())
            ->addIndex(Index::editor()->setUnquotedName('idx_b_a')->setUnquotedColumnNames('a_id')->create())
            ->addForeignKeyConstraint(
                ForeignKeyConstraint::editor()
                    ->setUnquotedName('fk_b_a')
                    ->setUnquotedReferencingColumnNames('a_id')
                    ->setUnquotedReferencedTableName('a')
                    ->setUnquotedReferencedColumnNames('id')
                    ->create(),
            )
            ->create();
    }

    /** @param non-empty-string $labelColumn */
    private static function category(string $labelColumn): Table
    {
        return Table::editor()
            ->setUnquotedName('category')
            ->setColumns(
                Column::editor()->setUnquotedName('id')->setTypeName(Types::INTEGER)->setAutoincrement(true)->create(),
                Column::editor()->setUnquotedName('parent_id')->setTypeName(Types::INTEGER)->setNotNull(false)->create(),
                Column::editor()->setUnquotedName($labelColumn)->setTypeName(Types::STRING)->create(),
            )
            ->setPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('id')->create())
            ->addIndex(
                Index::editor()->setUnquotedName('idx_category_label')->setUnquotedColumnNames($labelColumn)->create(),
            )
            ->addForeignKeyConstraint(
                ForeignKeyConstraint::editor()
                    ->setUnquotedName('fk_category_parent')
                    ->setUnquotedReferencingColumnNames('parent_id')
                    ->setUnquotedReferencedTableName('category')
                    ->setUnquotedReferencedColumnNames('id')
                    ->create(),
            )
            ->create();
    }
}
