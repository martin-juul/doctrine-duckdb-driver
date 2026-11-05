<?php

declare(strict_types=1);

namespace MartinJuul\DBAL\DuckDB\Tests\Functional\Schema;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\ComparatorConfig;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\SchemaDiff;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use MartinJuul\DBAL\DuckDB\Driver\StatementClassifier;
use MartinJuul\DBAL\DuckDB\Platform\DuckDBPlatform;
use MartinJuul\DBAL\DuckDB\Tests\Functional\FunctionalTestCase;

use function in_array;

/**
 * R26: foreign keys that form a cycle between tables lose one key per cycle,
 * and comparing the declared schema with the database reports no change.
 */
final class ForeignKeyCycleTest extends FunctionalTestCase
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

    public function testTheNullableKeyOfATwoTableCycleIsLeftOutAndTheOtherEnforced(): void
    {
        $schema = self::schema(self::users(), self::posts());

        $this->executeAll($this->platform->getCreateTablesSQL($schema->getTables()));

        self::assertSame(['posts(author_id) -> users(id)'], RebuildChecks::foreignKeys($this->connection));

        $this->connection->executeStatement('INSERT INTO users (id, last_post_id, score) VALUES (1, 99, 0)');

        RebuildChecks::assertForeignKeyRejects($this->connection, 'INSERT INTO posts (id, author_id) VALUES (1, 98)');

        $diff = $this->compareWithDatabase($schema);

        self::assertTrue($diff->isEmpty());
        self::assertSame([], $this->platform->getAlterSchemaSQL($diff));
        self::assertTrue($this->compareDatabaseWith($schema)->isEmpty());
    }

    public function testAThreeTableCycleLosesOneKeyAndReDiffsEmpty(): void
    {
        $schema = self::schema(...self::triangle());

        $this->executeAll($this->platform->getCreateTablesSQL($schema->getTables()));

        self::assertSame(
            ['a(b_id) -> b(id)', 'b(c_id) -> c(id)'],
            RebuildChecks::foreignKeys($this->connection),
        );
        self::assertTrue($this->compareWithDatabase($schema)->isEmpty());
        self::assertTrue($this->compareDatabaseWith($schema)->isEmpty());
        self::assertSame(
            ['DROP TABLE "a"', 'DROP TABLE "b"', 'DROP TABLE "c"'],
            $this->platform->getDropTablesSQL($schema->getTables()),
        );

        $this->executeAll($this->platform->getDropTablesSQL($schema->getTables()));

        self::assertSame([], $this->connection->createSchemaManager()->introspectTableNames());
    }

    /** Declaring the key that closes a cycle changes nothing: the key would be left out. */
    public function testDeclaringTheKeyThatClosesACycleIsNoChange(): void
    {
        $this->executeAll($this->platform->getCreateTablesSQL([self::users(withForeignKey: false), self::posts()]));

        $diff = $this->compareWithDatabase(self::schema(self::users(), self::posts()));

        self::assertTrue($diff->isEmpty());
        self::assertSame([], $this->platform->getAlterSchemaSQL($diff));
    }

    /** A schema update that creates one table of a cycle and rebuilds the other leaves out the planned key. */
    public function testCreatingOneTableOfACycleAndRebuildingTheOtherLeavesOutTheNullableKey(): void
    {
        $this->executeAll($this->platform->getCreateTablesSQL([self::posts(withForeignKey: false)]));

        $schema = self::schema(self::users(), self::posts());
        $diff   = $this->compareWithDatabase($schema);
        $sql    = $this->platform->getAlterSchemaSQL($diff);

        self::assertCount(1, $diff->getCreatedTables());
        self::assertContains(StatementClassifier::REBUILD_BEGIN, $sql);

        $this->executeAll($sql);

        self::assertSame(['posts(author_id) -> users(id)'], RebuildChecks::foreignKeys($this->connection));
        self::assertTrue($this->compareWithDatabase($schema)->isEmpty());
    }

    /**
     * Rebuilding a table of a cycle rebuilds the tables that reference it and
     * creates no left-out key; rebuilding the other table rebuilds only that
     * table, because the left-out key does not reference it.
     */
    public function testRebuildingATableOfACycleKeepsTheLeftOutKeyOut(): void
    {
        $this->executeAll($this->platform->getCreateTablesSQL([self::users(), self::posts()]));
        $this->connection->executeStatement('INSERT INTO users (id, last_post_id, score) VALUES (1, 1, 5), (2, NULL, 6)');
        $this->connection->executeStatement('INSERT INTO posts (id, author_id) VALUES (1, 1), (2, 1), (3, 2)');

        $schema = self::schema(self::users(nullableScore: true), self::posts());
        $sql    = $this->platform->getAlterSchemaSQL($this->compareWithDatabase($schema));

        self::assertContains('DROP TABLE "users"', $sql);
        self::assertContains('DROP TABLE "posts"', $sql);

        $this->executeAll($sql);

        self::assertSame(['posts(author_id) -> users(id)'], RebuildChecks::foreignKeys($this->connection));
        self::assertSame(
            [[1, 1, 5], [2, null, 6]],
            $this->connection->fetchAllNumeric('SELECT id, last_post_id, score FROM users ORDER BY id'),
        );
        self::assertSame(
            [[1, 1], [2, 1], [3, 2]],
            $this->connection->fetchAllNumeric('SELECT id, author_id FROM posts ORDER BY id'),
        );
        RebuildChecks::assertForeignKeyRejects($this->connection, 'INSERT INTO posts (id, author_id) VALUES (4, 98)');
        self::assertTrue($this->compareWithDatabase($schema)->isEmpty());

        $schema = self::schema(self::users(nullableScore: true), self::posts(withTitle: false));
        $sql    = $this->platform->getAlterSchemaSQL($this->compareWithDatabase($schema));

        self::assertContains('DROP TABLE "posts"', $sql);
        self::assertNotContains('DROP TABLE "users"', $sql);

        $this->executeAll($sql);

        self::assertSame(['posts(author_id) -> users(id)'], RebuildChecks::foreignKeys($this->connection));
        self::assertTrue($this->compareWithDatabase($schema)->isEmpty());
    }

    /** @param list<string> $statements */
    private function executeAll(array $statements): void
    {
        foreach ($statements as $statement) {
            $this->connection->executeStatement($statement);
        }
    }

    /** The diff from the database to the declared schema, as ORM's schema tool and `migrations:diff` compute it. */
    private function compareWithDatabase(Schema $schema): SchemaDiff
    {
        $schemaManager = $this->connection->createSchemaManager();

        return $schemaManager->createComparator((new ComparatorConfig())->withReportModifiedIndexes(false))
            ->compareSchemas(self::schema(...$schemaManager->introspectTables()), $schema);
    }

    /** The diff from the declared schema to the database, as the `down()` of `migrations:diff` computes it. */
    private function compareDatabaseWith(Schema $schema): SchemaDiff
    {
        $schemaManager = $this->connection->createSchemaManager();

        return $schemaManager->createComparator((new ComparatorConfig())->withReportModifiedIndexes(false))
            ->compareSchemas($schema, self::schema(...$schemaManager->introspectTables()));
    }

    private static function schema(Table ...$tables): Schema
    {
        return Schema::editor()->setDefaultNamespace('main')->setTables(...$tables)->create();
    }

    /** Users, whose nullable `last_post_id` references posts. */
    private static function users(bool $withForeignKey = true, bool $nullableScore = false): Table
    {
        return self::table(
            'users',
            ['id', 'last_post_id', 'score'],
            $withForeignKey ? [self::foreignKey('fk_users_last_post', 'last_post_id', 'posts')] : [],
            $nullableScore ? ['last_post_id', 'score'] : ['last_post_id'],
            ['idx_users_last_post' => 'last_post_id'],
        );
    }

    /** Posts, whose `author_id` references users and accepts no NULL. */
    private static function posts(bool $withForeignKey = true, bool $withTitle = true): Table
    {
        return self::table(
            'posts',
            $withTitle ? ['id', 'author_id', 'title'] : ['id', 'author_id'],
            $withForeignKey ? [self::foreignKey('fk_posts_author', 'author_id', 'users')] : [],
            ['title'],
            ['idx_posts_author' => 'author_id'],
        );
    }

    /**
     * a -> b -> c -> a, where only c's key accepts NULL.
     *
     * @return list<Table>
     */
    private static function triangle(): array
    {
        return [
            self::table('a', ['id', 'b_id'], [self::foreignKey('fk_a_b', 'b_id', 'b')], [], ['idx_a_b' => 'b_id']),
            self::table('b', ['id', 'c_id'], [self::foreignKey('fk_b_c', 'c_id', 'c')], [], ['idx_b_c' => 'c_id']),
            self::table(
                'c',
                ['id', 'a_id'],
                [self::foreignKey('fk_c_a', 'a_id', 'a')],
                ['a_id'],
                ['idx_c_a' => 'a_id'],
            ),
        ];
    }

    /**
     * An integer table with primary key `id`.
     *
     * @param non-empty-string                          $name
     * @param non-empty-list<non-empty-string>          $columns
     * @param list<ForeignKeyConstraint>                $foreignKeys
     * @param list<non-empty-string>                    $nullable    The columns that accept NULL.
     * @param array<non-empty-string, non-empty-string> $indexes     Index names and their column.
     */
    private static function table(
        string $name,
        array $columns,
        array $foreignKeys,
        array $nullable,
        array $indexes,
    ): Table {
        $editor = Table::editor()->setUnquotedName($name);

        foreach ($columns as $column) {
            $editor->addColumn(
                Column::editor()
                    ->setUnquotedName($column)
                    ->setTypeName(Types::INTEGER)
                    ->setNotNull(! in_array($column, $nullable, true))
                    ->create(),
            );
        }

        foreach ($indexes as $index => $column) {
            $editor->addIndex(
                Index::editor()->setUnquotedName($index)->setUnquotedColumnNames($column)->create(),
            );
        }

        return $editor
            ->setPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('id')->create())
            ->setForeignKeyConstraints(...$foreignKeys)
            ->create();
    }

    /**
     * @param non-empty-string $name
     * @param non-empty-string $column
     * @param non-empty-string $referencedTable
     */
    private static function foreignKey(string $name, string $column, string $referencedTable): ForeignKeyConstraint
    {
        return ForeignKeyConstraint::editor()
            ->setUnquotedName($name)
            ->setUnquotedReferencingColumnNames($column)
            ->setUnquotedReferencedTableName($referencedTable)
            ->setUnquotedReferencedColumnNames('id')
            ->create();
    }
}
