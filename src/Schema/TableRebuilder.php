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
use Doctrine\DBAL\Types\Type;
use MartinJuul\DBAL\DuckDB\Driver\StatementClassifier;
use MartinJuul\DBAL\DuckDB\Migrations\RebuildTables;
use MartinJuul\DBAL\DuckDB\Platform\DuckDBPlatform;

use function array_diff_key;
use function array_flip;
use function array_key_exists;
use function array_key_first;
use function array_keys;
use function array_map;
use function array_merge;
use function array_reverse;
use function count;
use function implode;
use function is_numeric;
use function preg_match;
use function preg_match_all;
use function rtrim;
use function spl_object_id;
use function sprintf;
use function str_ends_with;
use function str_replace;
use function str_starts_with;
use function strcasecmp;
use function stripos;
use function strlen;
use function strpos;
use function strtolower;
use function substr;

use const PREG_OFFSET_CAPTURE;
use const PREG_SET_ORDER;
use const PREG_UNMATCHED_AS_NULL;

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
 *    tables, so that IDs continue, leaving out the keys that the target
 *    schema leaves out to break cycles ({@see RebuildPlan});
 * 4. copies the rows back, parents first, from each old column to its new
 *    column, never by position;
 * 5. drops the staging tables, then recreates indexes and comments, and the
 *    indexes on expressions that the rebuild entries carry.
 *
 * The new tables also declare the CHECK constraints and column collations
 * that the rebuild entries carry from DuckDB's catalog. A CHECK constraint on
 * a renamed column is rewritten to the new name; one whose columns are all
 * dropped goes with them, as DuckDB's DROP COLUMN does. A CHECK constraint
 * that uses a dropped column together with a kept one, a rename the rewrite
 * cannot apply reliably, and a collated column whose new type cannot keep its
 * collation are refused. A rebuild entry that was built without reading the
 * catalog begins with a check that fails while its table has a generated
 * column, a CHECK constraint or a collation that the rebuild would lose.
 *
 * An autoincrement column whose values come from a column that was not one
 * gets a new owned sequence, which the rebuild moves past the copied values
 * when it runs.
 *
 * An index on expressions that uses a column the rebuild renames or drops is
 * not recreated. Instead, the rebuild begins with a check that fails, naming
 * the index, while the index exists, so a migration can drop it before the
 * change and create it again afterwards.
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
 * @phpstan-import-type CatalogCheck from RebuildPlan
 * @phpstan-import-type ExpressionIndex from RebuildPlan
 * @phpstan-import-type Rebuild from RebuildPlan
 * @phpstan-type Step array{sql: string, levels: array{table: string, depth: string}|null}
 */
final class TableRebuilder
{
    private const string STAGE_SUFFIX = '__stage';

    /**
     * The tokens of an SQL expression as DuckDB's catalog reports it: a
     * string literal, a quoted identifier (group 1), or a bare identifier
     * that is not followed by a parenthesis (group 2).
     */
    private const string TOKENS = '/\'(?:[^\']|\'\')*\'|"((?:[^"]|"")+)"|(?<![A-Za-z0-9_$])([A-Za-z_][A-Za-z0-9_$]*+)(?!\s*\()/';

    /** The type declarations that DuckDB stores as VARCHAR, the only type with a collation. */
    private const string STRING_TYPE = '/^(?:VARCHAR|TEXT|STRING|CHAR|BPCHAR|NVARCHAR)(?:\s*\(\s*\d+\s*\))?$/i';

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
     * registered for it, or table by table when there is none; then a
     * connection, when given, supplies what DBAL cannot represent of the
     * rebuilt tables and refuses tables with generated columns.
     *
     * @return list<Step>
     *
     * @throws Exception
     */
    public function schemaDiffSteps(SchemaDiff $diff, Connection|null $connection = null): array
    {
        $plan = $this->platform->getRebuildPlan($diff);

        if ($plan === null) {
            $plan = $this->withCatalogDetails(
                (new AlterRouting($this->platform))->plan($diff->getAlteredTables()),
                $connection,
            );
        }

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
            $this->tableSteps(
                $plan->rebuilds,
                $diff->getCreatedTables(),
                $diff->getDroppedTables(),
                $plan->omittedForeignKeys,
                $plan->omittedOldForeignKeys,
            ),
        );

        // A sequence that a dropped table's default uses can be dropped only after the table.
        foreach ($diff->getDroppedSequences() as $sequence) {
            $steps[] = self::step($this->platform->getDropSequenceSQL($sequence->getObjectName()->toSQL($this->platform)));
        }

        return $this->bracket($steps, $plan->rebuilds);
    }

    /**
     * The steps of a single table diff, without the context of a comparison:
     * a rebuild covers only this table. Only when a connection is given to
     * read DuckDB's catalog does it keep the indexes on expressions, CHECK
     * constraints and collations, and refuse a table with generated columns.
     *
     * @return list<Step>
     *
     * @throws Exception
     */
    public function tableDiffSteps(TableDiff $diff, Connection|null $connection = null): array
    {
        $plan  = $this->withCatalogDetails((new AlterRouting($this->platform))->plan([$diff]), $connection);
        $steps = [];

        foreach ($plan->nativeSQL as $statements) {
            foreach ($statements as $sql) {
                $steps[] = self::step($sql);
            }
        }

        return $this->bracket(
            array_merge($steps, $this->tableSteps($plan->rebuilds)),
            $plan->rebuilds,
        );
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
        return $this->bracket($this->tableSteps($rebuilds), $rebuilds);
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
     * The plan with each rebuild carrying what DBAL cannot represent of its
     * table, after refusing tables with generated columns, as the comparator
     * does.
     *
     * @throws Exception
     */
    private function withCatalogDetails(RebuildPlan $plan, Connection|null $connection): RebuildPlan
    {
        if ($connection === null || count($plan->rebuilds) === 0) {
            return $plan;
        }

        $old = [];

        foreach ($plan->rebuilds as $key => $rebuild) {
            $old[$key] = $rebuild['old'];
        }

        DuckDBComparator::assertNoGeneratedColumns($connection, $old);

        return new RebuildPlan(
            $plan->nativeSQL,
            DuckDBComparator::withCatalogDetails($connection, $this->platform, $plan->rebuilds),
        );
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
     * @param array<string, true>    $omittedForeignKeys    The keys that the target schema leaves out to break cycles.
     * @param array<string, true>    $omittedOldForeignKeys The keys that the old schema leaves out to break cycles.
     *
     * @return list<Step>
     */
    private function tableSteps(
        array $rebuilds,
        array $created = [],
        array $dropped = [],
        array $omittedForeignKeys = [],
        array $omittedOldForeignKeys = [],
    ): array {
        $rebuilds = array_map($this->withDeclaredColumnNames(...), $rebuilds);
        $steps    = [];
        $reused   = [];
        $orphaned = [];
        $new      = [];
        $recreate = [];

        foreach ($rebuilds as $key => $rebuild) {
            if (! $rebuild['catalogRead']) {
                $steps[] = $this->unreadCatalogCheck($rebuild['old']);
            }

            foreach ($rebuild['expressionIndexes'] as $index) {
                $column = self::lostColumn($rebuild, $index['sql']);

                if ($column === null) {
                    $recreate[$key][] = $index;

                    continue;
                }

                $steps[] = $this->lostColumnCheck($rebuild['old'], $index, $column);
            }
        }

        $checks = [];

        foreach ($rebuilds as $key => $rebuild) {
            [$table, $tableReused, $tableOrphaned] = $this->keepOwnedSequences($rebuild);

            $new[$key] = $this->withCollations($rebuild, $table);

            $checks[RebuildPlan::tableKey($table->getObjectName())] = $this->carriedChecks($rebuild);

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

        $toDrop = RebuildPlan::byKey($dropped);

        foreach ($rebuilds as $key => $rebuild) {
            $toDrop[$key] = $rebuild['old'];
        }

        $dropOrder = RebuildPlan::parentsFirst($toDrop, $this->platform->createdForeignKeys($toDrop, $omittedOldForeignKeys));

        foreach (array_reverse($dropOrder) as $key) {
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

        foreach ($this->platform->createTablesSQL($toCreate, $omittedForeignKeys, $checks) as $sql) {
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
            $steps = array_merge($steps, $this->sequenceStartSteps($rebuilds[$key], $new[$key]));
        }

        foreach ($order as $key) {
            if (count($rebuilds[$key]['columns']) === 0) {
                continue;
            }

            $steps[] = self::step($this->platform->getDropTableSQL($this->stageName($rebuilds[$key]['old'])));
        }

        $steps = array_merge($steps, $deferred);

        foreach ($order as $key) {
            foreach ($recreate[$key] ?? [] as $index) {
                $steps[] = self::step(sprintf(
                    'CREATE %sINDEX %s ON %s%s',
                    $index['unique'] ? 'UNIQUE ' : '',
                    Identifier::quoted($index['name'])->toSQL($this->platform),
                    $new[$key]->getObjectName()->toSQL($this->platform),
                    self::indexedExpressions($index['sql']),
                ));
            }
        }

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
     * Moves the new owned sequence of each autoincrement column whose values
     * come from a column that was not one past the copied values, so that
     * inserts without a value do not repeat them. The statements compute the
     * start when they run, not when they are generated.
     *
     * @param Rebuild $rebuild
     *
     * @return list<Step>
     */
    private function sequenceStartSteps(array $rebuild, Table $new): array
    {
        $columns = [];

        foreach ($new->getColumns() as $column) {
            $columns[RebuildPlan::columnKey($column)] = $column;
        }

        $target = $new->getObjectName()->toSQL($this->platform);
        $steps  = [];

        foreach ($rebuild['columns'] as [$oldColumn, $newColumn]) {
            $column = $columns[RebuildPlan::columnKey($newColumn)] ?? null;

            if ($column === null || ! $column->getAutoincrement() || $oldColumn->getAutoincrement()) {
                continue;
            }

            $sequence = $this->platform->getOwnedSequenceName($new->getObjectName(), $column)->toSQL($this->platform);

            $steps[] = self::step(sprintf(
                'SELECT setval(%s, max_value) FROM (SELECT max(%s) AS max_value FROM %s) WHERE max_value >= 1',
                $this->platform->quoteStringLiteral($sequence),
                $this->name($column),
                $target,
            ));
        }

        return $steps;
    }

    /**
     * The expressions of the CHECK constraints that the rebuild carries over,
     * with renamed columns under their new names. A constraint whose columns
     * the change all drops goes with them.
     *
     * @param Rebuild $rebuild
     *
     * @return list<string>
     *
     * @throws NotSupported When a constraint uses a dropped column and a kept one, or a rename cannot be applied.
     */
    private function carriedChecks(array $rebuild): array
    {
        $oldColumns = [];
        $kept       = [];
        $renamed    = [];

        foreach ($rebuild['old']->getColumns() as $column) {
            $oldColumns[RebuildPlan::columnKey($column)] = true;
        }

        foreach ($rebuild['columns'] as [$oldColumn, $newColumn]) {
            $key = RebuildPlan::columnKey($oldColumn);

            $kept[$key] = true;

            if ($key === RebuildPlan::columnKey($newColumn)) {
                continue;
            }

            $renamed[$key] = $this->name($newColumn);
        }

        $expressions = [];

        foreach ($rebuild['checks'] as $check) {
            $used    = 0;
            $dropped = [];
            $uses    = [];

            // DuckDB also lists the field of a struct column's field access, such as `s.f`, as a column.
            foreach ($check['columns'] as $column) {
                $key = strtolower($column);

                if (! isset($oldColumns[$key])) {
                    continue;
                }

                $used++;

                if (! isset($kept[$key])) {
                    $dropped[$key] = $column;

                    continue;
                }

                if (! isset($renamed[$key])) {
                    continue;
                }

                $uses[$key] = true;
            }

            if (count($dropped) > 0) {
                $reason = 'together with columns it keeps';

                if (count($dropped) === $used) {
                    $reason = null;

                    foreach (self::nameOccurrences($check['expression'], $dropped) as $occurrence) {
                        if (! $occurrence['field']) {
                            continue;
                        }

                        $reason = 'or a struct field of the same name';

                        break;
                    }
                }

                if ($reason === null) {
                    continue;
                }

                throw new NotSupported(sprintf(
                    'Cannot rebuild table %s: its CHECK constraint %s, CHECK (%s), uses column %s, which this change '
                        . 'drops, %s. Change the table with hand-written SQL.',
                    $rebuild['old']->getObjectName()->toString(),
                    $check['name'],
                    $check['expression'],
                    $dropped[array_key_first($dropped)],
                    $reason,
                ));
            }

            $expression = $check['expression'];

            if (count($uses) > 0) {
                $expression = self::renameColumns($expression, $renamed, $uses);
            }

            if ($expression === null) {
                throw new NotSupported(sprintf(
                    'Cannot rebuild table %s: its CHECK constraint %s, CHECK (%s), uses a column that this change '
                        . 'renames, and the new name cannot be applied to the expression reliably. Change the table '
                        . 'with hand-written SQL.',
                    $rebuild['old']->getObjectName()->toString(),
                    $check['name'],
                    $check['expression'],
                ));
            }

            $expressions[] = $expression;
        }

        return $expressions;
    }

    /**
     * The expression with each renamed column that it uses under its new
     * name, or null when that cannot be done reliably: when a renamed name
     * appears after a period, where it may name a field, or when the
     * identifiers replaced are not exactly the used columns that DuckDB's
     * catalog reports. A name after AS is a cast's type and stays.
     *
     * @param array<string, string> $renamed The new names as SQL, keyed by the old names in lower case.
     * @param array<string, true>   $uses    The renamed columns that the expression uses, in lower case.
     */
    private static function renameColumns(string $expression, array $renamed, array $uses): string|null
    {
        $result   = '';
        $offset   = 0;
        $replaced = [];

        foreach (self::nameOccurrences($expression, $renamed) as $occurrence) {
            if ($occurrence['field'] || ! isset($uses[$occurrence['key']])) {
                return null;
            }

            $result .= substr($expression, $offset, $occurrence['start'] - $offset) . $renamed[$occurrence['key']];
            $offset  = $occurrence['start'] + $occurrence['length'];

            $replaced[$occurrence['key']] = true;
        }

        if (count($replaced) !== count($uses)) {
            return null;
        }

        return $result . substr($expression, $offset);
    }

    /**
     * Where the given names appear in the expression as identifiers, except
     * after AS, as the type of a cast. `field` marks an occurrence after a
     * period, which may name a struct field rather than a column.
     *
     * @param array<string, mixed> $names Keyed by the names in lower case.
     *
     * @return list<array{key: string, start: int, length: int, field: bool}>
     */
    private static function nameOccurrences(string $expression, array $names): array
    {
        preg_match_all(
            self::TOKENS,
            $expression,
            $matches,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL,
        );

        $occurrences = [];

        foreach ($matches as $match) {
            $name = $match[1][0] ?? null;
            $name = $name === null ? $match[2][0] ?? null : str_replace('""', '"', $name);

            if ($name === null || ! isset($names[strtolower($name)])) {
                continue;
            }

            $start  = $match[0][1];
            $before = rtrim(substr($expression, 0, $start));

            if (preg_match('/(?<![A-Za-z0-9_$])AS$/i', $before) === 1) {
                continue;
            }

            $occurrences[] = [
                'key' => strtolower($name),
                'start' => $start,
                'length' => strlen((string) $match[0][0]),
                'field' => str_ends_with($before, '.'),
            ];
        }

        return $occurrences;
    }

    /**
     * The new table with each column whose values come from a collated column
     * declaring that collation.
     *
     * @param Rebuild $rebuild
     *
     * @throws NotSupported When the new type of a collated column cannot keep its collation.
     */
    private function withCollations(array $rebuild, Table $table): Table
    {
        $collations = [];

        foreach ($rebuild['columns'] as [$oldColumn, $newColumn]) {
            $collation = $rebuild['collations'][RebuildPlan::columnKey($oldColumn)] ?? null;

            if ($collation === null) {
                continue;
            }

            $collations[RebuildPlan::columnKey($newColumn)] = $collation;
        }

        if (count($collations) === 0) {
            return $table;
        }

        $columns = [];

        foreach ($table->getColumns() as $column) {
            $collation = $collations[RebuildPlan::columnKey($column)] ?? null;

            if ($collation !== null) {
                $column = $this->collated($rebuild['old'], $column, $collation);
            }

            $columns[] = $column;
        }

        return $table->edit()->setColumns(...$columns)->create();
    }

    /**
     * The column declaring the collation.
     *
     * @throws NotSupported When the column's type cannot keep the collation, or its own definition leaves it out.
     */
    private function collated(Table $old, Column $column, string $collation): Column
    {
        $definition = $column->getColumnDefinition();

        if ($definition !== null) {
            if (stripos($definition, 'COLLATE') !== false) {
                return $column;
            }

            throw new NotSupported(sprintf(
                'Cannot rebuild table %s: its column %s has the collation %s, and the column\'s new definition, '
                    . '%s, does not declare one. Add COLLATE %3$s after the type in the definition.',
                $old->getObjectName()->toString(),
                $column->getObjectName()->toString(),
                $collation,
                $definition,
            ));
        }

        $type = Type::getType($column->getTypeName())->getSQLDeclaration($column->toArray(true), $this->platform);

        if (preg_match('/^[A-Za-z0-9_.]+$/', $collation) !== 1 || preg_match(self::STRING_TYPE, $type) !== 1) {
            throw new NotSupported(sprintf(
                'Cannot rebuild table %s: its column %s has the collation %s, which the column\'s new type %s '
                    . 'cannot keep. Change the column with hand-written SQL.',
                $old->getObjectName()->toString(),
                $column->getObjectName()->toString(),
                $collation,
                $type,
            ));
        }

        return self::withPlatformOption($column, DuckDBPlatform::COLLATION_OPTION, $collation);
    }

    /**
     * A check that fails while the table has a generated column, a CHECK
     * constraint other than a column's minimum or maximum value, or a collated
     * column: what a rebuild that did not read DuckDB's catalog would lose.
     *
     * @return Step
     */
    private function unreadCatalogCheck(Table $old): array
    {
        $name      = $old->getObjectName();
        $qualifier = $name->getQualifier();
        $schema    = $qualifier === null
            ? 'current_schema()'
            : $this->platform->quoteStringLiteral($qualifier->getValue());
        $table     = $this->platform->quoteStringLiteral($name->getUnqualifiedName()->getValue());
        $where     = sprintf(
            'database_name = current_database() AND lower(schema_name) = lower(%s) AND lower(table_name) = lower(%s)',
            $schema,
            $table,
        );

        return self::step(sprintf(
            'SELECT CASE WHEN EXISTS (SELECT 1 FROM duckdb_columns() WHERE %1$s AND is_generated) OR EXISTS '
                . '(SELECT 1 FROM duckdb_constraints() WHERE %1$s AND constraint_type = \'CHECK\' AND NOT '
                . 'regexp_matches(expression, %2$s)) OR EXISTS (SELECT 1 FROM duckdb_tables() WHERE %1$s AND sql LIKE '
                . '\'%% COLLATE %%\') THEN error(%3$s) END',
            $where,
            $this->platform->quoteStringLiteral(DuckDBMetadataProvider::BOUND_PATTERN),
            $this->platform->quoteStringLiteral(sprintf(
                'Cannot rebuild table %s without reading DuckDB\'s catalog: it has a generated column, a CHECK '
                    . 'constraint or a collation that DBAL cannot represent, which the rebuild would lose. Apply the '
                    . 'change through the schema manager (alterSchema(), migrateSchema() or alterTable()) or a diff '
                    . 'from its comparator.',
                $name->toString(),
            )),
        ));
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
     * The foreign keys of the table that reference the table itself and that
     * the platform creates.
     *
     * @return list<ForeignKeyConstraint>
     */
    private function selfReferences(Table $table): array
    {
        $key        = RebuildPlan::tableKey($table->getObjectName());
        $references = [];

        foreach ($table->getForeignKeys() as $foreignKey) {
            if (
                RebuildPlan::tableKey($foreignKey->getReferencedTableName()) !== $key
                || ! $this->platform->createsForeignKey($foreignKey)
            ) {
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

    /**
     * The first column of the old table that the index's expressions use and
     * that the new table does not keep under the same name, or null.
     *
     * An identifier that is not followed by a parenthesis, outside string
     * literals, is taken to name a column.
     *
     * @param Rebuild $rebuild
     */
    private static function lostColumn(array $rebuild, string $sql): string|null
    {
        $kept = [];

        foreach ($rebuild['columns'] as [$oldColumn, $newColumn]) {
            if (RebuildPlan::columnKey($oldColumn) !== RebuildPlan::columnKey($newColumn)) {
                continue;
            }

            $kept[RebuildPlan::columnKey($oldColumn)] = true;
        }

        $oldColumns = [];

        foreach ($rebuild['old']->getColumns() as $column) {
            $oldColumns[RebuildPlan::columnKey($column)] = $column->getObjectName()->getIdentifier()->getValue();
        }

        preg_match_all(
            self::TOKENS,
            self::indexedExpressions($sql),
            $matches,
            PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL,
        );

        foreach ($matches as $match) {
            $name = $match[1] ?? null;
            $name = $name === null ? $match[2] ?? null : str_replace('""', '"', $name);

            if ($name === null) {
                continue;
            }

            $key = strtolower($name);

            if (! isset($oldColumns[$key]) || isset($kept[$key])) {
                continue;
            }

            return $oldColumns[$key];
        }

        return null;
    }

    /**
     * A check that fails, naming the index, while the index exists.
     *
     * @param ExpressionIndex $index
     *
     * @return Step
     */
    private function lostColumnCheck(Table $old, array $index, string $column): array
    {
        return self::step(sprintf(
            'SELECT CASE WHEN EXISTS (SELECT 1 FROM duckdb_indexes() WHERE database_name = current_database() '
                . 'AND schema_name = %s AND table_name = %s AND index_name = %s) THEN error(%s) END',
            $this->platform->quoteStringLiteral($index['schema']),
            $this->platform->quoteStringLiteral($index['table']),
            $this->platform->quoteStringLiteral($index['name']),
            $this->platform->quoteStringLiteral(sprintf(
                'Cannot rebuild table %s: its expression index %s uses column %s, which this change renames or '
                    . 'drops. Drop the index before this change and create it again afterwards, in the same '
                    . 'migration.',
                $old->getObjectName()->toString(),
                $index['name'],
                $column,
            )),
        ));
    }

    /**
     * The parenthesized expression list of a CREATE INDEX statement as
     * DuckDB's catalog reports it: `CREATE INDEX name ON table(expressions);`.
     */
    private static function indexedExpressions(string $sql): string
    {
        $sql   = rtrim(rtrim($sql), ';');
        $depth = 0;
        $quote = null;

        for ($position = strlen($sql) - 1; $position >= 0; $position--) {
            $character = $sql[$position];

            if ($quote !== null) {
                if ($character === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($character === "'" || $character === '"') {
                $quote = $character;

                continue;
            }

            if ($character === ')') {
                $depth++;

                continue;
            }

            if ($character !== '(') {
                continue;
            }

            $depth--;

            if ($depth === 0) {
                return substr($sql, $position);
            }
        }

        throw new NotSupported(sprintf('Cannot read the expressions of the index %s.', $sql));
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
     * The rebuild with each copied column of the new table under the name
     * that CREATE TABLE declares it with.
     *
     * DBAL's deprecated Table::renameColumn() changes only that name, and
     * leaves the column's object name, which the copy statements use, at the
     * old name. Such a column keeps the object name of the old column it
     * comes from, under a different declared name.
     *
     * @param Rebuild $rebuild
     *
     * @return Rebuild
     */
    private function withDeclaredColumnNames(array $rebuild): array
    {
        $renamed = [];

        foreach ($rebuild['columns'] as $position => [$oldColumn, $newColumn]) {
            $declared = $newColumn->toArray(true)['name'];

            if (
                strcasecmp(self::identifier($oldColumn), self::identifier($newColumn)) !== 0
                || strcasecmp($oldColumn->toArray(true)['name'], $declared) === 0
            ) {
                continue;
            }

            $current = self::copy($newColumn, $this->platform->quoteSingleIdentifier($declared), []);

            $renamed[spl_object_id($newColumn)] = $current;
            $rebuild['columns'][$position]      = [$oldColumn, $current];
        }

        if (count($renamed) === 0) {
            return $rebuild;
        }

        $columns = [];

        foreach ($rebuild['new']->getColumns() as $column) {
            $columns[] = $renamed[spl_object_id($column)] ?? $column;
        }

        $rebuild['new'] = $rebuild['new']->edit()->setColumns(...$columns)->create();

        return $rebuild;
    }

    private static function identifier(Column $column): string
    {
        return $column->getObjectName()->getIdentifier()->getValue();
    }

    /** A copy of the column with one platform option set; Column::edit() would drop the package's options. */
    private static function withPlatformOption(Column $column, string $name, mixed $value): Column
    {
        return self::copy($column, $column->getObjectName()->toString(), [$name => $value]);
    }

    /**
     * A copy of the column under the given name, with the given platform
     * options added to its own.
     *
     * @param array<string, mixed> $addedPlatformOptions
     */
    private static function copy(Column $column, string $name, array $addedPlatformOptions): Column
    {
        $properties      = $column->toArray(true);
        $platformOptions = array_diff_key($properties, array_flip(self::COLUMN_PROPERTIES));

        foreach ($addedPlatformOptions as $option => $value) {
            $platformOptions[$option] = $value;
        }

        return new Column($name, $column->getTypeName(), [
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
