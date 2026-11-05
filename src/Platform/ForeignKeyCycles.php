<?php

declare(strict_types=1);

namespace MartinJuul\DBAL\DuckDB\Platform;

use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Doctrine\DBAL\Schema\Name\OptionallyQualifiedName;
use Doctrine\DBAL\Schema\Table;
use MartinJuul\DBAL\DuckDB\Schema\RebuildPlan;

use function array_keys;
use function array_pop;
use function count;
use function implode;
use function ksort;
use function sort;
use function strtolower;

use const SORT_STRING;

/**
 * Chooses the foreign keys that generated DDL leaves out so that the keys
 * between a set of tables form no cycle.
 *
 * DuckDB 2.0 creates a foreign key only together with its table, and only
 * when the referenced table already exists, so it cannot create tables whose
 * keys reference each other in a cycle. A table that references itself forms
 * no such cycle, and neither does a key to a table outside the set.
 *
 * The choice is greedy: while a cycle remains, the key that lies on the most
 * remaining cycles is left out, so that a key shared by overlapping cycles
 * breaks them all. Among equal keys, one whose local columns all accept NULL
 * goes first, then the one with the smallest {@see self::identity()}, which
 * starts with the referencing table's name and its columns. Afterwards, a key
 * that later choices made unnecessary is created again. The choice depends
 * only on the tables and their keys, never on the order they are listed in.
 *
 * @internal
 */
final class ForeignKeyCycles
{
    /** The most cycles counted for one choice. Beyond it the counts are partial, but still deterministic. */
    private const int CYCLE_LIMIT = 10000;

    /** The most edges followed while counting cycles for one choice. */
    private const int STEP_LIMIT = 1000000;

    /**
     * The identities of the keys to leave out.
     *
     * @param list<Table>                               $tables
     * @param array<string, list<ForeignKeyConstraint>> $foreignKeys The keys that would be created, per
     *                                                               {@see RebuildPlan::tableKey()}.
     *
     * @return array<string, true>
     */
    public static function breakingKeys(array $tables, array $foreignKeys): array
    {
        $all     = self::edges($tables, $foreignKeys);
        $edges   = $all;
        $omitted = [];

        while (true) {
            $best      = null;
            $bestCount = 0;

            foreach (self::cycleCounts($edges) as $identity => $count) {
                if (
                    $best !== null
                    && (
                        $count < $bestCount
                        || ($count === $bestCount && ($edges[$best]['nullable'] || ! $edges[$identity]['nullable']))
                    )
                ) {
                    continue;
                }

                $best      = $identity;
                $bestCount = $count;
            }

            if ($best === null) {
                break;
            }

            $omitted[] = $best;

            unset($edges[$best]);
        }

        $left = [];

        foreach ($omitted as $identity) {
            $edges[$identity] = $all[$identity];

            if (! self::hasCycle($edges)) {
                continue;
            }

            unset($edges[$identity]);

            $left[$identity] = true;
        }

        return $left;
    }

    /**
     * A key's identity within a set of tables: the referencing table, its
     * column pairs in any order, and the referenced table. Constraint names
     * play no part, because DuckDB does not keep them.
     */
    public static function identity(OptionallyQualifiedName $table, ForeignKeyConstraint $foreignKey): string
    {
        $referencedColumns = $foreignKey->getReferencedColumnNames();
        $pairs             = [];

        foreach ($foreignKey->getReferencingColumnNames() as $position => $column) {
            $referenced = $referencedColumns[$position] ?? null;

            $pairs[] = strtolower($column->getIdentifier()->getValue()) . "\2"
                . ($referenced === null ? '' : strtolower($referenced->getIdentifier()->getValue()));
        }

        sort($pairs, SORT_STRING);

        return RebuildPlan::tableKey($table) . "\0" . implode("\1", $pairs) . "\0"
            . RebuildPlan::tableKey($foreignKey->getReferencedTableName());
    }

    /**
     * The keys between different tables of the set, by identity, in identity
     * order.
     *
     * @param list<Table>                               $tables
     * @param array<string, list<ForeignKeyConstraint>> $foreignKeys
     *
     * @return array<string, array{from: string, to: string, nullable: bool}>
     */
    private static function edges(array $tables, array $foreignKeys): array
    {
        $byKey = RebuildPlan::byKey($tables);
        $edges = [];

        foreach ($byKey as $key => $table) {
            $notNull = [];

            foreach ($table->getColumns() as $column) {
                $notNull[strtolower($column->getObjectName()->getIdentifier()->getValue())] = $column->getNotnull();
            }

            foreach ($foreignKeys[$key] ?? [] as $foreignKey) {
                $referenced = RebuildPlan::tableKey($foreignKey->getReferencedTableName());

                if ($referenced === $key || ! isset($byKey[$referenced])) {
                    continue;
                }

                $nullable = true;

                foreach ($foreignKey->getReferencingColumnNames() as $column) {
                    if (! ($notNull[strtolower($column->getIdentifier()->getValue())] ?? true)) {
                        continue;
                    }

                    $nullable = false;
                }

                $edges[self::identity($table->getObjectName(), $foreignKey)] ??= [
                    'from' => $key,
                    'to' => $referenced,
                    'nullable' => $nullable,
                ];
            }
        }

        ksort($edges, SORT_STRING);

        return $edges;
    }

    /**
     * For each key on a cycle, the number of simple cycles it lies on, in
     * identity order. Each cycle is found once, from its smallest table.
     *
     * @param array<string, array{from: string, to: string, nullable: bool}> $edges In identity order.
     *
     * @return array<string, int>
     */
    private static function cycleCounts(array $edges): array
    {
        $adjacency = [];
        $tables    = [];

        foreach ($edges as $identity => $edge) {
            $adjacency[$edge['from']][] = $identity;
            $tables[$edge['from']]      = true;
            $tables[$edge['to']]        = true;
        }

        $order = array_keys($tables);

        sort($order, SORT_STRING);

        $rank   = [];
        $counts = [];
        $budget = ['cycles' => self::CYCLE_LIMIT, 'steps' => self::STEP_LIMIT];

        foreach ($order as $position => $table) {
            $rank[$table] = $position;
        }

        foreach ($order as $start) {
            $path   = [];
            $onPath = [$start => true];

            self::walk($start, $start, $rank, $adjacency, $edges, $path, $onPath, $counts, $budget);
        }

        ksort($counts, SORT_STRING);

        return $counts;
    }

    /**
     * Follows every simple path from the table through tables ranked after
     * the start, counting the keys of each path that returns to the start.
     *
     * @param array<string, int>                                             $rank
     * @param array<string, list<string>>                                    $adjacency
     * @param array<string, array{from: string, to: string, nullable: bool}> $edges
     * @param list<string>                                                   $path
     * @param array<string, true>                                            $onPath
     * @param array<string, int>                                             $counts
     * @param array{cycles: int, steps: int}                                 $budget
     */
    private static function walk(
        string $start,
        string $table,
        array $rank,
        array $adjacency,
        array $edges,
        array &$path,
        array &$onPath,
        array &$counts,
        array &$budget,
    ): void {
        foreach ($adjacency[$table] ?? [] as $identity) {
            if ($budget['cycles'] === 0 || $budget['steps'] === 0) {
                return;
            }

            $budget['steps']--;

            $next = $edges[$identity]['to'];

            if ($next === $start) {
                $budget['cycles']--;

                foreach ([...$path, $identity] as $member) {
                    $counts[$member] = ($counts[$member] ?? 0) + 1;
                }

                continue;
            }

            if ($rank[$next] < $rank[$start] || isset($onPath[$next])) {
                continue;
            }

            $path[]        = $identity;
            $onPath[$next] = true;

            self::walk($start, $next, $rank, $adjacency, $edges, $path, $onPath, $counts, $budget);

            array_pop($path);
            unset($onPath[$next]);
        }
    }

    /** @param array<string, array{from: string, to: string, nullable: bool}> $edges */
    private static function hasCycle(array $edges): bool
    {
        $incoming = [];
        $outgoing = [];

        foreach ($edges as $edge) {
            $incoming[$edge['from']] ??= 0;
            $incoming[$edge['to']]     = ($incoming[$edge['to']] ?? 0) + 1;
            $outgoing[$edge['from']][] = $edge['to'];
        }

        $ready = [];

        foreach ($incoming as $table => $count) {
            if ($count !== 0) {
                continue;
            }

            $ready[] = $table;
        }

        $removed = 0;

        while (count($ready) > 0) {
            $table = array_pop($ready);

            $removed++;

            foreach ($outgoing[$table] ?? [] as $next) {
                $incoming[$next]--;

                if ($incoming[$next] !== 0) {
                    continue;
                }

                $ready[] = $next;
            }
        }

        return $removed < count($incoming);
    }
}
