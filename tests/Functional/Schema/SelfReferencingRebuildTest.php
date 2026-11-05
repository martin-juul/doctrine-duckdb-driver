<?php

declare(strict_types=1);

namespace MartinJuul\DBAL\DuckDB\Tests\Functional\Schema;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Platforms\Exception\NotSupported;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Comparator;
use Doctrine\DBAL\Schema\ComparatorConfig;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Doctrine\DBAL\Schema\ForeignKeyConstraint\ReferentialAction;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\Configuration\Configuration;
use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\Configuration\Migration\ExistingConfiguration;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Provider\SchemaProvider;
use Doctrine\Migrations\Provider\StubSchemaProvider;
use MartinJuul\DBAL\DuckDB\Migrations\RebuildTables;
use MartinJuul\DBAL\DuckDB\Platform\DuckDBPlatform;
use MartinJuul\DBAL\DuckDB\Schema\DuckDBSchemaManager;
use MartinJuul\DBAL\DuckDB\Tests\Functional\FunctionalTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

use function array_values;
use function mkdir;
use function rmdir;
use function scandir;
use function sys_get_temp_dir;
use function uniqid;

/** Covers AE17. */
final class SelfReferencingRebuildTest extends FunctionalTestCase
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
    public function testTheSchemaManagerRebuildsAThreeLevelTreeKeepingEveryRowAndParentLink(bool $file): void
    {
        $this->open($file);
        $this->createTree();

        $before = RebuildChecks::data($this->connection, 'category', 'id', ['id', 'parent_id', 'name']);
        $max    = 30;
        $target = new Schema([self::category('title')]);

        $this->schemaManager()->migrateSchema($target);
        $this->reopen();

        $after = RebuildChecks::data($this->connection, 'category', 'id', ['id', 'parent_id', 'title']);

        self::assertSame($before['rows'], $after['rows']);
        self::assertSame(array_values($before['columns']), array_values($after['columns']));
        self::assertSame(['category(parent_id) -> category(id)'], RebuildChecks::foreignKeys($this->connection));
        self::assertSame(
            ['category.IDX_64C19C1727ACA70 [parent_id]', 'category.idx_category_label [title]'],
            RebuildChecks::indexes($this->connection),
        );
        RebuildChecks::assertNoStagingTables($this->connection);
        RebuildChecks::assertForeignKeyRejects(
            $this->connection,
            "INSERT INTO category (parent_id, title) VALUES (999, 'orphan')",
        );
        RebuildChecks::assertNextIdExceeds(
            $this->connection,
            "INSERT INTO category (parent_id, title) VALUES (1, 'new') RETURNING id",
            $max,
        );

        $diff = $this->schemaManager()
            ->createComparator((new ComparatorConfig())->withReportModifiedIndexes(false))
            ->compareSchemas($target, $this->schemaManager()->introspectSchema());

        self::assertSame([], $diff->getAlteredTables());
        self::assertFalse($this->connection->isTransactionActive());
    }

    public function testGeneratedSqlRefusesTheRebuildNamingTheMigrationHelper(): void
    {
        $this->open(false);
        $this->createTree();

        $diff = $this->schemaManager()
            ->createComparator((new ComparatorConfig())->withReportModifiedIndexes(false))
            ->compareSchemas($this->schemaManager()->introspectSchema(), new Schema([self::category('title')]));

        $this->expectException(NotSupported::class);
        $this->expectExceptionMessage(RebuildTables::class . '::queue()');

        $this->platform()->getAlterSchemaSQL($diff);
    }

    /**
     * A self-reference that requests ON DELETE CASCADE is never created, so a
     * rebuild copies the table in one statement, even from a table diff that
     * declares the key.
     */
    public function testASelfReferenceThatIsLeftOutRebuildsInGeneratedSql(): void
    {
        $this->open(false);
        $this->create(self::category('name', onDelete: ReferentialAction::CASCADE));
        $this->connection->executeStatement(
            "INSERT INTO category (id, parent_id, name) VALUES (1, NULL, 'root'), (2, 1, 'child'), (3, 99, 'orphan')",
        );

        $diff = (new Comparator($this->platform()))->compareTables(
            self::category('name', onDelete: ReferentialAction::CASCADE),
            self::category('title', onDelete: ReferentialAction::CASCADE),
        );

        foreach ($this->platform()->getAlterTableSQL($diff) as $sql) {
            $this->connection->executeStatement($sql);
        }

        self::assertSame(
            [[1, null, 'root'], [2, 1, 'child'], [3, 99, 'orphan']],
            $this->connection->fetchAllNumeric('SELECT id, parent_id, title FROM category ORDER BY id'),
        );
        self::assertSame([], RebuildChecks::foreignKeys($this->connection));
    }

    public function testMigrationsDiffFailsNamingTheMigrationHelper(): void
    {
        $this->open(false);
        $this->createTree();

        $directory = sys_get_temp_dir() . '/doctrine-duckdb-driver-migrations-' . uniqid();
        mkdir($directory);

        try {
            $configuration = new Configuration();
            $configuration->addMigrationsDirectory('DuckDBDiff', $directory);

            $dependencyFactory = DependencyFactory::fromConnection(
                new ExistingConfiguration($configuration),
                new ExistingConnection($this->connection),
            );
            $dependencyFactory->setService(
                SchemaProvider::class,
                new StubSchemaProvider(new Schema([self::category('title')])),
            );

            try {
                $dependencyFactory->getDiffGenerator()->generate('DuckDBDiff\\Version1', null);
                self::fail('migrations:diff generated SQL for a self-referencing rebuild.');
            } catch (NotSupported $e) {
                self::assertStringContainsString(RebuildTables::class . '::queue()', $e->getMessage());
            }

            self::assertSame(['.', '..'], scandir($directory));
        } finally {
            rmdir($directory);
        }
    }

    public function testRowsWhoseParentIsMissingFailTheRebuildAndRollItBack(): void
    {
        $this->open(false);

        $withoutKey = self::category('name', foreignKey: false);

        $this->create($withoutKey);
        $this->connection->executeStatement(
            "INSERT INTO category (id, parent_id, name) VALUES (1, NULL, 'root'), (2, 1, 'child'), (3, 42, 'lost')",
        );

        $before = RebuildChecks::data($this->connection, 'category', 'id', ['id', 'parent_id', 'name']);

        try {
            $this->schemaManager()->migrateSchema(new Schema([self::category('name')]));
            self::fail('The rebuild copied a row whose parent is missing.');
        } catch (DriverException $e) {
            self::assertStringContainsString('left rows uncopied', $e->getMessage());
        }

        self::assertFalse($this->connection->isTransactionActive());
        self::assertSame($before, RebuildChecks::data($this->connection, 'category', 'id', ['id', 'parent_id', 'name']));
        self::assertSame([], RebuildChecks::foreignKeys($this->connection));
        RebuildChecks::assertNoStagingTables($this->connection);
    }

    private function createTree(): void
    {
        $this->create(self::category('name'));

        // The owned sequence has handed out IDs up to 30; the explicit IDs below descend with depth, so copying in
        // ID order would insert children before their parents.
        $this->connection->fetchAllNumeric(
            "SELECT nextval('\"category_id" . DuckDBPlatform::OWNED_SEQUENCE_SUFFIX . "\"') FROM range(30)",
        );

        $this->connection->executeStatement(<<<'SQL'
            INSERT INTO category (id, parent_id, name) VALUES (10, NULL, 'root'), (20, NULL, 'second root')
            SQL);
        $this->connection->executeStatement(<<<'SQL'
            INSERT INTO category (id, parent_id, name) VALUES (5, 10, 'child'), (6, 10, 'child 2'), (7, 20, 'child 3')
            SQL);
        $this->connection->executeStatement(<<<'SQL'
            INSERT INTO category (id, parent_id, name) VALUES (1, 5, 'grandchild'), (2, 5, 'grandchild 2'),
                (3, 7, 'grandchild 3')
            SQL);
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

    /** @param non-empty-string $labelColumn */
    private static function category(
        string $labelColumn,
        bool $foreignKey = true,
        ReferentialAction $onDelete = ReferentialAction::NO_ACTION,
    ): Table {
        $editor = Table::editor()
            ->setUnquotedName('category')
            ->setColumns(
                Column::editor()->setUnquotedName('id')->setTypeName(Types::INTEGER)->setAutoincrement(true)->create(),
                Column::editor()->setUnquotedName('parent_id')->setTypeName(Types::INTEGER)->setNotNull(false)->create(),
                Column::editor()->setUnquotedName($labelColumn)->setTypeName(Types::STRING)->create(),
            )
            ->setPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('id')->create())
            ->addIndex(
                Index::editor()->setUnquotedName('idx_category_label')->setUnquotedColumnNames($labelColumn)->create(),
            );

        if ($foreignKey) {
            $editor->addForeignKeyConstraint(
                ForeignKeyConstraint::editor()
                    ->setUnquotedName('fk_category_parent')
                    ->setUnquotedReferencingColumnNames('parent_id')
                    ->setUnquotedReferencedTableName('category')
                    ->setUnquotedReferencedColumnNames('id')
                    ->setOnDeleteAction($onDelete)
                    ->create(),
            );
        }

        return $editor->create();
    }
}
