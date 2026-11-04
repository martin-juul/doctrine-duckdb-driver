<?php

declare(strict_types=1);

namespace MartinJuul\DBAL\DuckDB\Tests\Functional\Migrations;

use Closure;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\TableNotFoundException;
use MartinJuul\DBAL\DuckDB\Tests\Functional\FunctionalTestCase;

final class AllOrNothingTest extends FunctionalTestCase
{
    public function testTwoMigrationsRunInOneTransaction(): void
    {
        $connection = self::connect();
        $levels     = [];
        $runner     = (new MigrationRunner($connection))
            ->add(self::createTable('first', $levels))
            ->add(self::createTable('second', $levels));

        $runner->migrate(allOrNothing: true);

        // Migrations opens the all-or-nothing transaction, then one per migration inside it; up() runs in both.
        self::assertSame([2, 2], $levels);
        self::assertSame([1], $connection->fetchFirstColumn('SELECT n FROM first'));
        self::assertSame([1], $connection->fetchFirstColumn('SELECT n FROM second'));
        self::assertSame(['Version001', 'Version002'], $runner->executedVersions());
        self::assertFalse($connection->isTransactionActive());
    }

    public function testAFailingSecondMigrationLeavesNeitherApplied(): void
    {
        $connection = self::connect();
        $levels     = [];
        $runner     = (new MigrationRunner($connection))
            ->add(self::createTable('first', $levels))
            ->add(static function (Connection $connection, Closure $addSql): void {
                $addSql('CREATE TABLE second (n INTEGER)');
                $addSql('INSERT INTO missing VALUES (1)');
            });

        try {
            $runner->migrate(allOrNothing: true);
            self::fail('The failing migration was applied.');
        } catch (TableNotFoundException) {
        }

        self::assertFalse($connection->isTransactionActive());
        self::assertSame(['doctrine_migration_versions'], $connection->createSchemaManager()->listTableNames());
        self::assertSame([], $runner->executedVersions());
    }

    /**
     * @param list<int> $levels
     *
     * @return Closure(Connection, Closure(string): void): void
     */
    private static function createTable(string $name, array &$levels): Closure
    {
        return static function (Connection $connection, Closure $addSql) use ($name, &$levels): void {
            $levels[] = $connection->getTransactionNestingLevel();

            $addSql('CREATE TABLE ' . $name . ' (n INTEGER)');
            $addSql('INSERT INTO ' . $name . ' VALUES (1)');
        };
    }
}
