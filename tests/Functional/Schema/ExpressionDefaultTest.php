<?php

declare(strict_types=1);

namespace MartinJuul\DBAL\DuckDB\Tests\Functional\Schema;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Comparator;
use Doctrine\DBAL\Schema\ComparatorConfig;
use Doctrine\DBAL\Schema\DefaultExpression\CurrentTimestamp;
use MartinJuul\DBAL\DuckDB\Tests\Functional\FunctionalTestCase;

use function array_column;

final class ExpressionDefaultTest extends FunctionalTestCase
{
    /**
     * An expression default, on a native column or on a mapped one, is kept
     * as an expression: a rebuild declares it again, so later inserts still
     * get it, and the rebuilt table re-diffs empty.
     */
    public function testExpressionDefaultsSurviveARebuild(): void
    {
        $connection = self::connect();

        $connection->executeStatement(<<<'SQL'
            CREATE TABLE expression_defaults (
                id INTEGER,
                ns TIMESTAMP_NS DEFAULT now(),
                ts TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                s TIMESTAMP_S DEFAULT CURRENT_TIMESTAMP,
                sum INTEGER DEFAULT (1 + 2),
                huge HUGEINT DEFAULT (1 + 2),
                label VARCHAR DEFAULT concat('a', 'b'),
                ratio DOUBLE DEFAULT (0.5 + 0.25),
                dropped INTEGER
            )
            SQL);
        $connection->executeStatement('CREATE INDEX idx_expression_defaults_id ON expression_defaults (id)');
        $connection->executeStatement('INSERT INTO expression_defaults (id) VALUES (1)');

        $schemaManager = $connection->createSchemaManager();
        $current       = $schemaManager->introspectTableByUnquotedName('expression_defaults');

        self::assertInstanceOf(CurrentTimestamp::class, $current->getColumn('ns')->getDefault());
        self::assertInstanceOf(CurrentTimestamp::class, $current->getColumn('s')->getDefault());

        // Dropping a column of a table with an index rebuilds it.
        $target = $current->edit()->dropColumnByUnquotedName('dropped')->create();

        $schemaManager->alterTable(self::comparator($connection)->compareTables($current, $target));

        $connection->executeStatement('INSERT INTO expression_defaults (id) VALUES (2)');

        $rows = $connection->fetchAllAssociative(<<<'SQL'
            SELECT id,
                   ns IS NOT NULL AS ns,
                   ts IS NOT NULL AS ts,
                   s IS NOT NULL AS s,
                   sum,
                   CAST(huge AS VARCHAR) AS huge,
                   label,
                   ratio
            FROM expression_defaults
            ORDER BY id
            SQL);

        self::assertSame([1, 2], array_column($rows, 'id'));

        foreach ($rows as $row) {
            self::assertSame(
                ['ns' => true, 'ts' => true, 's' => true, 'sum' => 3, 'huge' => '3', 'label' => 'ab', 'ratio' => 0.75],
                [
                    'ns' => $row['ns'],
                    'ts' => $row['ts'],
                    's' => $row['s'],
                    'sum' => $row['sum'],
                    'huge' => $row['huge'],
                    'label' => $row['label'],
                    'ratio' => $row['ratio'],
                ],
            );
        }

        $rebuilt = $schemaManager->introspectTableByUnquotedName('expression_defaults');

        self::assertFalse($rebuilt->hasColumn('dropped'));
        self::assertTrue(self::comparator($connection)->compareTables($rebuilt, $target)->isEmpty());
    }

    private static function comparator(Connection $connection): Comparator
    {
        return $connection->createSchemaManager()->createComparator(
            (new ComparatorConfig())->withReportModifiedIndexes(false),
        );
    }
}
