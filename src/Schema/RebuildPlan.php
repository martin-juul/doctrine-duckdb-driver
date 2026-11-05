<?php

declare(strict_types=1);

namespace MartinJuul\DBAL\DuckDB\Schema;

use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Doctrine\DBAL\Schema\Name\OptionallyQualifiedName;
use Doctrine\DBAL\Schema\Name\UnqualifiedName;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Schema\TableDiff;

use function array_keys;
use function array_map;
use function array_pop;
use function count;
use function implode;
use function sort;
use function strtolower;

/**
 * How a schema diff reaches DuckDB: the native ALTER statements of the
 * tables that DuckDB can alter in place, and the tables that must be rebuilt.
 *
 * {@see DuckDBComparator} computes the plan and registers it with the
 * platform for the diff it returns, because DBAL's SchemaDiff cannot carry
 * it. A rebuilt table is copied column by column: each pair names the old
 * column a value comes from and the new column it goes to.
 *
 * A rebuild also carries what DBAL cannot represent of the old table, as
 * DuckDB's catalog reports it: the indexes on expressions, which
 * introspection skips, the CHECK constraints other than the minimum and
 * maximum values introspection maps to column options, and the columns'
 * collations. Only a caller with a connection can read them; `catalogRead`
 * says whether one did.
 *
 * The plan also names the foreign keys that the compared schemas leave out
 * to break cycles between their tables, keyed by the identity that
 * {@see \MartinJuul\DBAL\DuckDB\Platform\ForeignKeyCycles::identity()}
 * gives them.
 *
 * @internal
 *
 * @phpstan-type ExpressionIndex array{schema: string, table: string, name: non-empty-string, unique: bool, sql: string}
 * @phpstan-type CatalogCheck array{name: string, expression: string, columns: list<string>}
 * @phpstan-type Rebuild array{
 *     old: Table,
 *     new: Table,
 *     columns: list<array{Column, Column}>,
 *     expressionIndexes: list<ExpressionIndex>,
 *     checks: list<CatalogCheck>,
 *     collations: array<string, string>,
 *     catalogRead: bool,
 * }
 */
final class RebuildPlan
{
    /**
     * @param array<string, list<string>> $nativeSQL             The native statements per altered table, keyed by
     *                                                           {@see tableKey()}.
     * @param array<string, Rebuild>      $rebuilds              The tables to rebuild, keyed by {@see tableKey()} of
     *                                                           the old table.
     * @param array<string, true>         $omittedForeignKeys    The foreign keys that the target schema leaves out
     *                                                           to break cycles, which creating tables leaves out.
     * @param array<string, true>         $omittedOldForeignKeys The foreign keys that the old schema leaves out to
     *                                                           break cycles, which the drop order ignores.
     */
    public function __construct(
        public readonly array $nativeSQL,
        public readonly array $rebuilds,
        public readonly array $omittedForeignKeys = [],
        public readonly array $omittedOldForeignKeys = [],
    ) {
    }

    /**
     * A rebuild entry whose columns are paired by the diff's renames, or by
     * name when there is no diff. What it carries from DuckDB's catalog is
     * added by {@see DuckDBComparator::withCatalogDetails()}; the collations
     * are keyed by {@see columnKey()} of the old column.
     *
     * @return Rebuild
     */
    public static function rebuild(Table $old, Table $new, TableDiff|null $diff): array
    {
        return [
            'old' => $old,
            'new' => $new,
            'columns' => self::pairColumns($old, $new, $diff),
            'expressionIndexes' => [],
            'checks' => [],
            'collations' => [],
            'catalogRead' => false,
        ];
    }

    /**
     * Pairs each column of the new table with the old column its values come
     * from. A column the diff renamed or changed comes from its old column, an
     * added column from none, and any other column from the old column of the
     * same name.
     *
     * @return list<array{Column, Column}>
     */
    public static function pairColumns(Table $old, Table $new, TableDiff|null $diff): array
    {
        $fromDiff = [];
        $added    = [];

        if ($diff !== null) {
            foreach ($diff->getChangedColumns() as $columnDiff) {
                $fromDiff[self::columnKey($columnDiff->getNewColumn())] = $columnDiff->getOldColumn();
            }

            foreach ($diff->getAddedColumns() as $column) {
                $added[self::columnKey($column)] = true;
            }
        }

        $oldColumns = [];

        foreach ($old->getColumns() as $column) {
            $oldColumns[self::columnKey($column)] = $column;
        }

        $pairs = [];

        foreach ($new->getColumns() as $column) {
            $key = self::columnKey($column);

            if (isset($fromDiff[$key])) {
                $pairs[] = [$fromDiff[$key], $column];

                continue;
            }

            if (isset($added[$key]) || ! isset($oldColumns[$key])) {
                continue;
            }

            $pairs[] = [$oldColumns[$key], $column];
        }

        return $pairs;
    }

    /** DuckDB matches identifiers case-insensitively, quoted or not. */
    public static function tableKey(OptionallyQualifiedName $name): string
    {
        $key       = strtolower($name->getUnqualifiedName()->getValue());
        $qualifier = $name->getQualifier();

        if ($qualifier === null) {
            return $key;
        }

        return strtolower($qualifier->getValue()) . '.' . $key;
    }

    /**
     * The tables keyed by {@see tableKey()}; of tables with the same key, the
     * last one wins.
     *
     * @param iterable<Table> $tables
     *
     * @return array<string, Table>
     */
    public static function byKey(iterable $tables): array
    {
        $byKey = [];

        foreach ($tables as $table) {
            $byKey[self::tableKey($table->getObjectName())] = $table;
        }

        return $byKey;
    }

    /**
     * Per referenced table, the tables whose foreign keys reference it, all
     * keyed by {@see tableKey()}.
     *
     * @param array<string, Table> $tables The tables, keyed by {@see tableKey()}.
     *
     * @return array<string, array<string, true>>
     */
    public static function referencedBy(array $tables): array
    {
        $referencedBy = [];

        foreach ($tables as $key => $table) {
            foreach ($table->getForeignKeys() as $foreignKey) {
                $referencedBy[self::tableKey($foreignKey->getReferencedTableName())][$key] = true;
            }
        }

        return $referencedBy;
    }

    /**
     * The given tables and every table of the allowed ones that references
     * them, transitively, in the order they are reached.
     *
     * @param array<string, true>                $keys         The tables to start from, keyed by {@see tableKey()}.
     * @param array<string, array<string, true>> $referencedBy As {@see referencedBy()} returns it.
     * @param array<string, Table>               $allowed      The tables that may join, keyed by {@see tableKey()}.
     *
     * @return array<string, true>
     */
    public static function referencingClosure(array $keys, array $referencedBy, array $allowed): array
    {
        $queue = array_keys($keys);

        while (count($queue) > 0) {
            $key = array_pop($queue);

            foreach (array_keys($referencedBy[$key] ?? []) as $child) {
                if (isset($keys[$child]) || ! isset($allowed[$child])) {
                    continue;
                }

                $keys[$child] = true;
                $queue[]      = $child;
            }
        }

        return $keys;
    }

    /**
     * Orders tables so that every table follows the tables it references;
     * self-references and references to tables outside the set are ignored.
     *
     * @param array<string, Table>                      $tables      The tables, keyed by {@see tableKey()}.
     * @param array<string, list<ForeignKeyConstraint>> $foreignKeys The foreign keys to follow, per table. They form
     *                                                               no cycle between tables.
     *
     * @return list<string> The keys of the tables.
     */
    public static function parentsFirst(array $tables, array $foreignKeys): array
    {
        $sorted  = [];
        $visited = [];

        foreach (array_keys($tables) as $key) {
            self::visit($key, $tables, $foreignKeys, $visited, $sorted);
        }

        return $sorted;
    }

    /**
     * The foreign key with its column pairs in the given order of their
     * positions.
     *
     * @param list<int> $order
     */
    public static function reorderForeignKey(ForeignKeyConstraint $foreignKey, array $order): ForeignKeyConstraint
    {
        $referencingColumns = $foreignKey->getReferencingColumnNames();
        $referencedColumns  = $foreignKey->getReferencedColumnNames();

        return $foreignKey->edit()
            ->setReferencingColumnNames(...array_map(
                static fn (int $position): UnqualifiedName => $referencingColumns[$position],
                $order,
            ))
            ->setReferencedColumnNames(...array_map(
                static fn (int $position): UnqualifiedName => $referencedColumns[$position],
                $order,
            ))
            ->create();
    }

    public static function columnKey(Column $column): string
    {
        return strtolower($column->getObjectName()->getIdentifier()->getValue());
    }

    /**
     * An order-insensitive key of a column set.
     *
     * @param list<UnqualifiedName|string> $columns
     */
    public static function columnSetKey(array $columns): string
    {
        $names = array_map(
            static fn (UnqualifiedName|string $column): string => strtolower(
                $column instanceof UnqualifiedName ? $column->getIdentifier()->getValue() : $column,
            ),
            $columns,
        );

        sort($names);

        return implode("\0", $names);
    }

    /**
     * @param array<string, Table>                      $tables
     * @param array<string, list<ForeignKeyConstraint>> $foreignKeys
     * @param array<string, true>                       $visited
     * @param list<string>                              $sorted
     */
    private static function visit(string $key, array $tables, array $foreignKeys, array &$visited, array &$sorted): void
    {
        if (isset($visited[$key])) {
            return;
        }

        $visited[$key] = true;

        foreach ($foreignKeys[$key] ?? [] as $foreignKey) {
            $parent = self::tableKey($foreignKey->getReferencedTableName());

            if ($parent === $key || ! isset($tables[$parent])) {
                continue;
            }

            self::visit($parent, $tables, $foreignKeys, $visited, $sorted);
        }

        $sorted[] = $key;
    }
}
