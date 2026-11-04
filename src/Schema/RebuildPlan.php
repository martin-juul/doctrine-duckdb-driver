<?php

declare(strict_types=1);

namespace MartinJuul\DBAL\DuckDB\Schema;

use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Name\OptionallyQualifiedName;
use Doctrine\DBAL\Schema\Name\UnqualifiedName;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Schema\TableDiff;

use function array_map;
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
 * @internal
 *
 * @phpstan-type Rebuild array{old: Table, new: Table, columns: list<array{Column, Column}>}
 */
final class RebuildPlan
{
    /**
     * @param array<string, list<string>> $nativeSQL The native statements per altered table, keyed by {@see tableKey()}.
     * @param array<string, Rebuild>      $rebuilds  The tables to rebuild, keyed by {@see tableKey()} of the old table.
     */
    public function __construct(public readonly array $nativeSQL, public readonly array $rebuilds)
    {
    }

    /**
     * A rebuild entry whose columns are paired by the diff's renames, or by
     * name when there is no diff.
     *
     * @return Rebuild
     */
    public static function rebuild(Table $old, Table $new, TableDiff|null $diff): array
    {
        return [
            'old' => $old,
            'new' => $new,
            'columns' => self::pairColumns($old, $new, $diff),
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
}
