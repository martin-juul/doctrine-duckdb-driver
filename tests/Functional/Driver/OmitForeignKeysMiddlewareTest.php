<?php

declare(strict_types=1);

namespace MartinJuul\DBAL\DuckDB\Tests\Functional\Driver;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Doctrine\DBAL\Schema\ForeignKeyConstraint\ReferentialAction;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use MartinJuul\DBAL\DuckDB\Driver;
use MartinJuul\DBAL\DuckDB\Driver\Middleware\OmitForeignKeys;
use MartinJuul\DBAL\DuckDB\Platform\DuckDBPlatform;
use MartinJuul\DBAL\DuckDB\Tests\Functional\FunctionalTestCase;

use function implode;
use function sys_get_temp_dir;

final class OmitForeignKeysMiddlewareTest extends FunctionalTestCase
{
    public function testGeneratedDdlHasNoForeignKeys(): void
    {
        $connection = DriverManager::getConnection(
            ['driverClass' => Driver::class, 'memory' => true],
            (new Configuration())->setMiddlewares([new OmitForeignKeys()]),
        );

        $platform = $connection->getDatabasePlatform();

        self::assertInstanceOf(DuckDBPlatform::class, $platform);
        self::assertTrue($platform->omitsForeignKeys());

        $statements = $platform->getCreateTablesSQL(self::mutuallyReferencingTables());

        self::assertStringNotContainsString('FOREIGN KEY', implode("\n", $statements));

        foreach ($statements as $statement) {
            $connection->executeStatement($statement);
        }

        self::assertSame(2, $connection->fetchOne('SELECT count(*) FROM duckdb_tables()'));
        self::assertSame(0, $connection->fetchOne(
            "SELECT count(*) FROM duckdb_constraints() WHERE constraint_type = 'FOREIGN KEY'",
        ));
    }

    public function testSelectingThePlatformDoesNotConnect(): void
    {
        $connection = DriverManager::getConnection(
            [
                'driverClass' => Driver::class,
                'dbname' => sys_get_temp_dir() . '/doctrine-duckdb-driver-missing-directory/never-created.duckdb',
            ],
            (new Configuration())->setMiddlewares([new OmitForeignKeys()]),
        );

        self::assertInstanceOf(DuckDBPlatform::class, $connection->getDatabasePlatform());
        self::assertFalse($connection->isConnected());
    }

    public function testOtherPlatformsPassThroughUnchanged(): void
    {
        $connection = DriverManager::getConnection(
            ['driver' => 'pdo_sqlite', 'memory' => true],
            (new Configuration())->setMiddlewares([new OmitForeignKeys()]),
        );

        $platform = $connection->getDatabasePlatform();

        self::assertInstanceOf(SQLitePlatform::class, $platform);

        $tables = [self::parent(), self::child()];
        $sql    = $platform->getCreateTablesSQL($tables);

        self::assertSame((new SQLitePlatform())->getCreateTablesSQL($tables), $sql);
        self::assertStringContainsString('FOREIGN KEY', implode("\n", $sql));
    }

    /** @return list<Table> */
    private static function mutuallyReferencingTables(): array
    {
        $users = self::table('users', ['id', 'last_post_id'], self::foreignKey('last_post_id', 'posts'));
        $posts = self::table('posts', ['id', 'author_id'], self::foreignKey('author_id', 'users'));

        return [$users, $posts];
    }

    private static function parent(): Table
    {
        return self::table('parent', ['id']);
    }

    private static function child(): Table
    {
        return self::table('child', ['id', 'parent_id'], self::foreignKey('parent_id', 'parent'));
    }

    /**
     * @param non-empty-string                 $name
     * @param non-empty-list<non-empty-string> $columns
     */
    private static function table(string $name, array $columns, ForeignKeyConstraint ...$foreignKeys): Table
    {
        $editor = Table::editor()->setUnquotedName($name);

        foreach ($columns as $column) {
            $editor->addColumn(Column::editor()->setUnquotedName($column)->setTypeName(Types::INTEGER)->create());
        }

        return $editor
            ->setPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('id')->create())
            ->setForeignKeyConstraints(...$foreignKeys)
            ->create();
    }

    /**
     * @param non-empty-string $column
     * @param non-empty-string $referencedTable
     */
    private static function foreignKey(string $column, string $referencedTable): ForeignKeyConstraint
    {
        return ForeignKeyConstraint::editor()
            ->setUnquotedName('fk_' . $column)
            ->setUnquotedReferencingColumnNames($column)
            ->setUnquotedReferencedTableName($referencedTable)
            ->setUnquotedReferencedColumnNames('id')
            ->setOnDeleteAction(ReferentialAction::CASCADE)
            ->create();
    }
}
