<?php

declare(strict_types=1);

namespace MartinJuul\DBAL\DuckDB\Schema;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Platforms\Exception\NotSupported;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Doctrine\DBAL\Schema\Name\Identifier;
use Doctrine\DBAL\Schema\Name\OptionallyQualifiedName;
use Doctrine\DBAL\Schema\Name\Parsers;
use Doctrine\DBAL\Schema\Name\UnqualifiedName;
use Doctrine\DBAL\Schema\SchemaDiff;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Schema\TableDiff;
use MartinJuul\DBAL\DuckDB\Driver\StatementClassifier;
use MartinJuul\DBAL\DuckDB\Migrations\RebuildTables;
use MartinJuul\DBAL\DuckDB\Platform\DuckDBPlatform;

use function array_diff_key;
use function array_flip;
use function array_key_exists;
use function array_keys;
use function array_merge;
use function array_reverse;
use function count;
use function implode;
use function is_numeric;
use function sprintf;
use function str_starts_with;
use function strlen;
use function strpos;
use function substr;

/**
 * Produces the statements that apply a schema diff, rebuilding the tables
 * that DuckDB 2.0 cannot alter in place.
 *
 * A rebuild never renames a table into place, because DuckDB refuses to
 * rename a table that has an index or that a foreign key references, and a
 * renamed self-referencing table keeps a foreign key to its old name.
 * Instead it:
 *
 * 1. copies each rebuilt table into a staging table, with explicit columns;
 * 2. drops the original tables and the dropped tables, children first;
 * 3. creates the new and the rebuilt tables under their final names, parents
 *    first, with inline foreign keys and the owned sequences of the old
 *    tables, so that IDs continue;
 * 4. copies the rows back, parents first, from each old column to its new
 *    column, never by position;
 * 5. drops the staging tables, then recreates indexes and comments.
 *
 * DuckDB checks a foreign key against the state before each statement, so a
 * self-referencing table is copied one tree level per statement until none
 * remain. The number of statements depends on the data, so generated SQL
 * cannot express it ({@see toSQL()}); the schema manager runs the loop
 * ({@see run()}), and the migration helper counts the levels first
 * ({@see withLevels()}).
 *
 * The statements of a rebuild are bracketed by the driver's rebuild markers,
 * so a rebuild without a surrounding transaction runs in one the driver owns.
 *
 * @internal
 *
 * @phpstan-import-type Rebuild from RebuildPlan
 * @phpstan-type Step array{sql: string, levels: array{table: string, depth: string}|null}
 */
final class TableRebuilder
{
    private const string STAGE_SUFFIX = '__stage';

    /** The keys of a column's properties in {@see Column::toArray()}; any other key is a platform option. */
    private const array COLUMN_PROPERTIES = [
        'name',
        'typeName',
        'default',
        'notnull',
        'length',
        'precision',
        'scale',
        'fixed',
        'unsigned',
        'autoincrement',
        'columnDefinition',
        'comment',
        'values',
    ];

    public function __construct(private readonly DuckDBPlatform $platform)
    {
    }

    /**
     * The steps of a schema diff, routed by the plan the comparator
     * registered for it, or table by table when there is none.
     *
     * @return list<Step>
     */
    public function schemaDiffSteps(SchemaDiff $diff): array
    {
        $plan = $this->platform->getRebuildPlan($diff)
            ?? (new AlterRouting($this->platform))->plan($diff->getAlteredTables());

        $steps = [];

        if ($this->platform->supportsSchemas()) {
            foreach ($diff->getCreatedSchemas() as $schema) {
                $steps[] = self::step($this->platform->getCreateSchemaSQL($schema));
            }
        }

        foreach ($diff->getAlteredSequences() as $sequence) {
            $steps[] = self::step($this->platform->getAlterSequenceSQL($sequence));
        }

        foreach ($diff->getCreatedSequences() as $sequence) {
            $steps[] = self::step($this->platform->getCreateSequenceSQL($sequence));
        }

        foreach ($diff->getAlteredTables() as $tableDiff) {
            $key = RebuildPlan::tableKey($tableDiff->getOldTable()->getObjectName());

            foreach ($plan->nativeSQL[$key] ?? [] as $sql) {
                $steps[] = self::step($sql);
            }
        }

        $steps = array_merge(
            $steps,
            $this->tableSteps($plan->rebuilds, $diff->getCreatedTables(), $diff->getDroppedTables()),
        );

        // A sequence that a dropped table's default uses can be dropped only after the table.
        foreach ($diff->getDroppedSequences() as $sequence) {
            $steps[] = self::step($this->platform->getDropSequenceSQL($sequence->getObjectName()->toSQL($this->platform)));
        }

        return $this->bracket($steps, $plan->rebuilds);
    }

    /**
     * The steps of a single table diff, without the context of a comparison:
     * a rebuild covers only this table.
     *
     * @return list<Step>
     */
    public function tableDiffSteps(TableDiff $diff): array
    {
        $plan  = (new AlterRouting($this->platform))->plan([$diff]);
        $steps = [];

        foreach ($plan->nativeSQL as $statements) {
            foreach ($statements as $sql) {
                $steps[] = self::step($sql);
            }
        }

        return $this->bracket(array_merge($steps, $this->tableSteps($plan->rebuilds, [], [])), $plan->rebuilds);
    }

    /**
     * The steps that rebuild the given tables, bracketed by rebuild markers.
     *
     * @param array<string, Rebuild> $rebuilds
     *
     * @return list<Step>
     */
    public function rebuildSteps(array $rebuilds): array
    {
        return $this->bracket($this->tableSteps($rebuilds, [], []), $rebuilds);
    }

    /**
     * The statements of the steps.
     *
     * @param list<Step> $steps
     *
     * @return list<string>
     *
     * @throws NotSupported When a self-referencing table must be rebuilt.
     */
    public function toSQL(array $steps): array
    {
        $sql = [];

        foreach ($steps as $step) {
            if ($step['levels'] !== null) {
                throw new NotSupported(sprintf(
                    'Rebuilding the self-referencing table %s copies it one tree level per statement, and the number '
                        . 'of levels depends on its data, so the rebuild cannot be expressed as generated SQL. Apply '
                        . 'the change through the schema manager (alterSchema(), migrateSchema() or alterTable()), or '
                        . 'from a migration with %s::queue().',
                    $step['levels']['table'],
                    RebuildTables::class,
                ));
            }

            $sql[] = $step['sql'];
        }

        return $sql;
    }

    /**
     * Runs the steps, repeating each level copy until it copies no rows.
     *
     * @param list<Step> $steps
     *
     * @throws Exception
     */
    public function run(Connection $connection, array $steps): void
    {
        foreach ($steps as $step) {
            if ($step['levels'] === null) {
                $connection->executeStatement($step['sql']);

                continue;
            }

            do {
                $copied = $connection->executeStatement($step['sql']);
            } while ($copied > 0);
        }
    }

    /**
     * The statements of the steps, with each level copy repeated once per
     * level of the current data, which a read-only query counts.
     *
     * @param list<Step> $steps
     *
     * @return list<string>
     *
     * @throws Exception
     */
    public function withLevels(Connection $connection, array $steps): array
    {
        $sql = [];

        foreach ($steps as $step) {
            if ($step['levels'] === null) {
                $sql[] = $step['sql'];

                continue;
            }

            $levels = $connection->fetchOne($step['levels']['depth']);
            $levels = is_numeric($levels) ? (int) $levels : 0;

            for ($level = 0; $level < $levels; $level++) {
                $sql[] = $step['sql'];
            }
        }

        return $sql;
    }

    /**
     * @param list<Step>             $steps
     * @param array<string, Rebuild> $rebuilds
     *
     * @return list<Step>
     */
    private function bracket(array $steps, array $rebuilds): array
    {
        if (count($rebuilds) === 0) {
            return $steps;
        }

        return [
            self::step(StatementClassifier::REBUILD_BEGIN),
            ...$steps,
            self::step(StatementClassifier::REBUILD_END),
        ];
    }

    /**
     * @param array<string, Rebuild> $rebuilds
     * @param array<Table>           $created
     * @param array<Table>           $dropped
     *
     * @return list<Step>
     */
    private function tableSteps(array $rebuilds, array $created, array $dropped): array
    {
        $steps    = [];
        $reused   = [];
        $orphaned = [];
        $new      = [];

        foreach ($rebuilds as $key => $rebuild) {
            [$new[$key], $tableReused, $tableOrphaned] = $this->keepOwnedSequences($rebuild);

            $reused   = array_merge($reused, $tableReused);
            $orphaned = array_merge($orphaned, $tableOrphaned);

            $sources = [];

            foreach ($rebuild['columns'] as [$oldColumn]) {
                $sources[RebuildPlan::columnKey($oldColumn)] = $this->name($oldColumn);
            }

            if (count($sources) === 0) {
                continue;
            }

            $steps[] = self::step(sprintf(
                'CREATE TABLE %s AS SELECT %s FROM %s',
                $this->stageName($rebuild['old']),
                implode(', ', $sources),
                $rebuild['old']->getObjectName()->toSQL($this->platform),
            ));
        }

        $toDrop = [];

        foreach ($dropped as $table) {
            $toDrop[RebuildPlan::tableKey($table->getObjectName())] = $table;
        }

        foreach ($rebuilds as $key => $rebuild) {
            $toDrop[$key] = $rebuild['old'];
        }

        foreach (array_reverse(self::parentsFirst($toDrop)) as $key) {
            if (isset($rebuilds[$key])) {
                $steps[] = self::step($this->platform->getDropTableSQL(
                    $rebuilds[$key]['old']->getObjectName()->toSQL($this->platform),
                ));

                continue;
            }

            foreach ($this->platform->getDropTablesSQL([$toDrop[$key]]) as $sql) {
                $steps[] = self::step($sql);
            }
        }

        $toCreate = [];

        foreach ($created as $table) {
            $toCreate[] = $table;
        }

        foreach ($new as $table) {
            $toCreate[] = $table;
        }

        $rebuiltByNewKey = [];

        foreach ($new as $oldKey => $table) {
            $rebuiltByNewKey[RebuildPlan::tableKey($table->getObjectName())] = $oldKey;
        }

        $deferred = [];
        $order    = [];
        $current  = null;

        foreach ($this->platform->getCreateTablesSQL($toCreate) as $sql) {
            if (str_starts_with($sql, 'CREATE SEQUENCE ')) {
                if (! isset($reused[$sql])) {
                    $steps[] = self::step($sql);
                }

                continue;
            }

            if (str_starts_with($sql, 'CREATE TABLE ')) {
                $current = $rebuiltByNewKey[self::createdTableKey($sql)] ?? null;

                if ($current !== null) {
                    $order[] = $current;
                }

                $steps[] = self::step($sql);

                continue;
            }

            if ($current !== null) {
                $deferred[] = self::step($sql);

                continue;
            }

            $steps[] = self::step($sql);
        }

        foreach ($order as $key) {
            $steps = array_merge($steps, $this->copySteps($rebuilds[$key], $new[$key]));
        }

        foreach ($order as $key) {
            if (count($rebuilds[$key]['columns']) === 0) {
                continue;
            }

            $steps[] = self::step($this->platform->getDropTableSQL($this->stageName($rebuilds[$key]['old'])));
        }

        $steps = array_merge($steps, $deferred);

        foreach (array_keys($orphaned) as $sequence) {
            $steps[] = self::step('DROP SEQUENCE IF EXISTS ' . $sequence);
        }

        return $steps;
    }

    /**
     * The new table, with each autoincrement column whose values come from an
     * autoincrement column drawing from that column's owned sequence, so IDs
     * continue. Also returns the CREATE SEQUENCE statements that must not run
     * because the sequence exists, and the sequences no column uses anymore.
     *
     * @param Rebuild $rebuild
     *
     * @return array{Table, array<string, true>, array<string, true>}
     */
    private function keepOwnedSequences(array $rebuild): array
    {
        $old     = $rebuild['old'];
        $sources = [];

        foreach ($rebuild['columns'] as [$oldColumn, $newColumn]) {
            $sources[RebuildPlan::columnKey($newColumn)] = $oldColumn;
        }

        $kept    = [];
        $reused  = [];
        $columns = [];

        foreach ($rebuild['new']->getColumns() as $column) {
            $source = $sources[RebuildPlan::columnKey($column)] ?? null;

            if ($column->getAutoincrement() && $source !== null && $source->getAutoincrement()) {
                $sequence = $this->platform->getOwnedSequenceName($old->getObjectName(), $source)->toSQL($this->platform);

                $column = self::withPlatformOption($column, DuckDBPlatform::OWNED_SEQUENCE_OPTION, $sequence);

                $kept[$sequence]                        = true;
                $reused['CREATE SEQUENCE ' . $sequence] = true;
            }

            $columns[] = $column;
        }

        $orphaned = [];

        foreach ($old->getColumns() as $column) {
            if (! $column->getAutoincrement()) {
                continue;
            }

            $sequence = $this->platform->getOwnedSequenceName($old->getObjectName(), $column)->toSQL($this->platform);

            if (isset($kept[$sequence])) {
                continue;
            }

            $orphaned[$sequence] = true;
        }

        if (count($kept) === 0) {
            return [$rebuild['new'], $reused, $orphaned];
        }

        return [$rebuild['new']->edit()->setColumns(...$columns)->create(), $reused, $orphaned];
    }

    /**
     * Copies the staged rows into the new table. A self-referencing table is
     * copied one level per statement: rows whose parents are all present,
     * until none remain; leftover rows then fail the rebuild.
     *
     * @param Rebuild $rebuild
     *
     * @return list<Step>
     */
    private function copySteps(array $rebuild, Table $new): array
    {
        if (count($rebuild['columns']) === 0) {
            return [];
        }

        $stage   = $this->stageName($rebuild['old']);
        $target  = $new->getObjectName()->toSQL($this->platform);
        $targets = [];
        $sources = [];
        $byNew   = [];

        foreach ($rebuild['columns'] as [$oldColumn, $newColumn]) {
            $targets[] = $this->name($newColumn);
            $sources[] = 's.' . $this->name($oldColumn);

            $byNew[RebuildPlan::columnKey($newColumn)] = $this->name($oldColumn);
        }

        $insert = sprintf(
            'INSERT INTO %s (%s) SELECT %s FROM %s s',
            $target,
            implode(', ', $targets),
            implode(', ', $sources),
            $stage,
        );

        $selfReferences = $this->selfReferences($new);

        if (count($selfReferences) === 0) {
            return [self::step($insert)];
        }

        $table      = $new->getObjectName()->toString();
        $primaryKey = $new->getPrimaryKeyConstraint();
        $identity   = $primaryKey?->getColumnNames() ?? $selfReferences[0]->getReferencedColumnNames();

        $identityMatch = [];
        $identityNull  = [];

        foreach ($identity as $column) {
            $source = $this->source($byNew, $column, $table);

            $identityMatch[] = 'c.' . $column->toSQL($this->platform) . ' = s.' . $source;
            $identityNull[]  = 's.' . $source . ' IS NULL';
        }

        $parentsPresent = [];
        $parentsInOld   = [];
        $roots          = [];

        foreach ($selfReferences as $foreignKey) {
            $nulls    = [];
            $matches  = [];
            $oldMatch = [];

            foreach ($foreignKey->getReferencingColumnNames() as $position => $column) {
                $referenced = $foreignKey->getReferencedColumnNames()[$position];
                $source     = $this->source($byNew, $column, $table);

                $nulls[]    = 's.' . $source . ' IS NULL';
                $matches[]  = 'p.' . $referenced->toSQL($this->platform) . ' = s.' . $source;
                $oldMatch[] = 'c.' . $source . ' = p.' . $this->source($byNew, $referenced, $table);
            }

            $parentsPresent[] = '(' . implode(' OR ', $nulls) . ' OR EXISTS (SELECT 1 FROM ' . $target . ' p WHERE '
                . implode(' AND ', $matches) . '))';
            $roots[]          = '(' . implode(' OR ', $nulls) . ')';
            $parentsInOld[]   = '(' . implode(' AND ', $oldMatch) . ')';
        }

        $level = $insert . ' WHERE NOT EXISTS (SELECT 1 FROM ' . $target . ' c WHERE ' . implode(' AND ', $identityMatch)
            . ') AND ' . implode(' AND ', $parentsPresent);

        if ($primaryKey === null) {
            $level .= ' AND NOT (' . implode(' OR ', $identityNull) . ')';
        }

        $old   = $rebuild['old']->getObjectName()->toSQL($this->platform);
        $depth = sprintf(
            'WITH RECURSIVE levels(r, depth) AS (SELECT s.rowid, 0 FROM %1$s s WHERE %2$s UNION SELECT c.rowid, '
                . 'l.depth + 1 FROM levels l JOIN %1$s p ON p.rowid = l.r JOIN %1$s c ON %3$s WHERE l.depth < '
                . '(SELECT count(*) FROM %1$s)) SELECT COALESCE(MAX(depth) + 1, 0) FROM levels',
            $old,
            implode(' AND ', $roots),
            implode(' OR ', $parentsInOld),
        );

        $steps = [['sql' => $level, 'levels' => ['table' => $table, 'depth' => $depth]]];

        if ($primaryKey === null) {
            $steps[] = self::step(
                $insert . ' WHERE (' . implode(' OR ', $identityNull) . ') AND ' . implode(' AND ', $parentsPresent),
            );
        }

        $steps[] = self::step(sprintf(
            'SELECT CASE WHEN (SELECT count(*) FROM %s) <> (SELECT count(*) FROM %s) THEN error(%s) END',
            $stage,
            $target,
            $this->platform->quoteStringLiteral(sprintf(
                'Rebuilding the self-referencing table %s left rows uncopied: their parent rows are missing or form '
                    . 'a cycle.',
                $table,
            )),
        ));

        return $steps;
    }

    /**
     * The foreign keys of the table that reference the table itself.
     *
     * @return list<ForeignKeyConstraint>
     */
    private function selfReferences(Table $table): array
    {
        if ($this->platform->omitsForeignKeys()) {
            return [];
        }

        $key        = RebuildPlan::tableKey($table->getObjectName());
        $references = [];

        foreach ($table->getForeignKeys() as $foreignKey) {
            if (RebuildPlan::tableKey($foreignKey->getReferencedTableName()) !== $key) {
                continue;
            }

            $references[] = $foreignKey;
        }

        return $references;
    }

    /** @param array<string, string> $byNew */
    private function source(array $byNew, UnqualifiedName $column, string $table): string
    {
        $key = RebuildPlan::columnSetKey([$column]);

        if (! array_key_exists($key, $byNew)) {
            throw new NotSupported(sprintf(
                'Cannot rebuild the self-referencing table %s: its key column %s has no column to copy from.',
                $table,
                $column->toString(),
            ));
        }

        return $byNew[$key];
    }

    /** The {@see RebuildPlan::tableKey()} of the table a CREATE TABLE statement creates. */
    private static function createdTableKey(string $sql): string
    {
        $name = substr($sql, strlen('CREATE TABLE '));
        $end  = strpos($name, ' (');

        if ($end !== false) {
            $name = substr($name, 0, $end);
        }

        return RebuildPlan::tableKey(Parsers::parseOptionallyQualifiedName($name));
    }

    private function stageName(Table $table): string
    {
        $name        = $table->getObjectName();
        $unqualified = $name->getUnqualifiedName();
        $value       = $unqualified->getValue() . self::STAGE_SUFFIX;
        $identifier  = $unqualified->isQuoted() ? Identifier::quoted($value) : Identifier::unquoted($value);

        return (new OptionallyQualifiedName($identifier, $name->getQualifier()))->toSQL($this->platform);
    }

    private function name(Column $column): string
    {
        return $column->getObjectName()->toSQL($this->platform);
    }

    /**
     * Orders tables so that every table follows the tables it references;
     * self-references and references to tables outside the set are ignored.
     *
     * @param array<string, Table> $tables
     *
     * @return list<string>
     */
    private static function parentsFirst(array $tables): array
    {
        $sorted  = [];
        $visited = [];

        foreach (array_keys($tables) as $key) {
            self::visit($key, $tables, $visited, $sorted);
        }

        return $sorted;
    }

    /**
     * @param array<string, Table> $tables
     * @param array<string, true>  $visited
     * @param list<string>         $sorted
     */
    private static function visit(string $key, array $tables, array &$visited, array &$sorted): void
    {
        if (isset($visited[$key])) {
            return;
        }

        $visited[$key] = true;

        foreach ($tables[$key]->getForeignKeys() as $foreignKey) {
            $parent = RebuildPlan::tableKey($foreignKey->getReferencedTableName());

            if ($parent === $key || ! isset($tables[$parent])) {
                continue;
            }

            self::visit($parent, $tables, $visited, $sorted);
        }

        $sorted[] = $key;
    }

    /** A copy of the column with one platform option set; Column::edit() would drop the package's options. */
    private static function withPlatformOption(Column $column, string $name, mixed $value): Column
    {
        $properties      = $column->toArray(true);
        $platformOptions = array_diff_key($properties, array_flip(self::COLUMN_PROPERTIES));

        $platformOptions[$name] = $value;

        return new Column($column->getObjectName()->toString(), $column->getTypeName(), [
            'length' => $column->getLength(),
            'precision' => $column->getPrecision(),
            'scale' => $column->getScale(),
            'unsigned' => $column->getUnsigned(),
            'fixed' => $column->getFixed(),
            'notnull' => $column->getNotnull(),
            'default' => $column->getDefault(),
            'autoincrement' => $column->getAutoincrement(),
            'comment' => $column->getComment(),
            'values' => $column->getValues(),
            'columnDefinition' => $column->getColumnDefinition(),
            'platformOptions' => $platformOptions,
        ]);
    }

    /** @return Step */
    private static function step(string $sql): array
    {
        return ['sql' => $sql, 'levels' => null];
    }
}
