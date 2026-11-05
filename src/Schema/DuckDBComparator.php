<?php

declare(strict_types=1);

namespace MartinJuul\DBAL\DuckDB\Schema;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Platforms\Exception\NotSupported;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Comparator;
use Doctrine\DBAL\Schema\ComparatorConfig;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\Index\IndexedColumn;
use Doctrine\DBAL\Schema\Name\UnqualifiedName;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\SchemaDiff;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Schema\TableDiff;
use Doctrine\DBAL\Types\Type;
use MartinJuul\DBAL\DuckDB\Platform\DuckDBPlatform;
use MartinJuul\DBAL\DuckDB\Platform\ForeignKeyCycles;

use function array_intersect_key;
use function array_keys;
use function array_map;
use function array_merge;
use function count;
use function implode;
use function in_array;
use function is_array;
use function is_scalar;
use function is_string;
use function preg_match;
use function sprintf;
use function str_replace;
use function strlen;
use function strtolower;
use function substr;
use function trim;

/**
 * Compares schemas and decides, per altered table, between native ALTER
 * statements and a rebuild.
 *
 * Besides DBAL's comparison it sees autoincrement and CHECK (minimum and
 * maximum value) changes, which only a rebuild can apply. A rebuilt table
 * pulls in every table that references it, transitively, as the old schema's
 * foreign keys say, because DuckDB cannot drop a referenced table. A table
 * that a new foreign key references without a PRIMARY KEY or UNIQUE
 * constraint on the referenced columns is rebuilt too, because DuckDB needs
 * such a constraint and creates one only with its table. A table with
 * generated columns cannot be rebuilt: DuckDB reports a generated column like
 * a plain column with a default, so a rebuild would turn it into one.
 *
 * The decision is registered with the platform for the returned SchemaDiff,
 * which DBAL's SchemaDiff cannot carry; the platform reads it in
 * {@see DuckDBPlatform::getAlterSchemaSQL()}.
 *
 * The comparison ignores the foreign keys that the platform leaves out of
 * generated DDL (see {@see DuckDBPlatform::createsForeignKey()}): those with a
 * referential action DuckDB cannot enforce, or all of them when the platform
 * omits foreign keys. Introspection never reports such keys, so a diff never
 * proposes adding or dropping one, and they neither cause rebuilds nor pull
 * referencing tables into one. A composite foreign key that the platform
 * created with its column pairs in the referenced key's order is compared in
 * the order of the index on its columns, as it was declared.
 *
 * {@see self::compareSchemas()} also ignores the keys that the new schema
 * leaves out to break cycles between its tables (see
 * {@see DuckDBPlatform::omittedCycleForeignKeys()}), on both sides, and the
 * keys that the old schema leaves out for its own cycles, on the old side.
 * A declared schema with cycles therefore compares equal to the database
 * created from it, in both directions. The diff's created tables still
 * declare their left-out keys; the registered plan names them, so that
 * {@see DuckDBPlatform::getAlterSchemaSQL()} leaves them out too. Rebuild
 * closures and drop order follow the keys the old schema creates.
 *
 * {@see self::compareTables()} sees a single table and therefore no cycle:
 * comparing one table of a cycle with its declaration proposes adding the
 * left-out key, and applying that diff fails at the rebuild's drop, because
 * the other table of the cycle references the table. ORM's schema tool and
 * validator, Doctrine Migrations and DBAL's schema manager compare schemas.
 *
 * With a connection, each rebuild also carries what DBAL cannot represent of
 * its table: the indexes on expressions, the CHECK constraints other than
 * minimum and maximum values, and the columns' collations, so that the
 * rebuild recreates them. A native ALTER COLUMN TYPE to a type without
 * collation drops a column's collation, which the platform's logger records
 * as a notice.
 *
 * @phpstan-import-type CatalogCheck from RebuildPlan
 * @phpstan-import-type ExpressionIndex from RebuildPlan
 * @phpstan-import-type Rebuild from RebuildPlan
 */
final class DuckDBComparator extends Comparator
{
    /** @internal Use {@see DuckDBSchemaManager::createComparator()}. */
    public function __construct(
        private readonly DuckDBPlatform $platform,
        ComparatorConfig $config = new ComparatorConfig(),
        private readonly Connection|null $connection = null,
    ) {
        parent::__construct($platform, $config);
    }

    /**
     * While {@see self::compareSchemas()} runs: the keys left out of the old
     * and of the new schema's tables, keyed by {@see ForeignKeyCycles::identity()}.
     *
     * @var array{old: array<string, true>, new: array<string, true>}
     */
    private array $omitted = ['old' => [], 'new' => []];

    /** @throws Exception */
    public function compareSchemas(Schema $oldSchema, Schema $newSchema): SchemaDiff
    {
        $oldOmitted = $this->platform->omittedCycleForeignKeys($oldSchema->getTables());
        $newOmitted = $this->platform->omittedCycleForeignKeys($newSchema->getTables());

        $this->omitted = ['old' => $oldOmitted + $newOmitted, 'new' => $newOmitted];

        try {
            $diff = parent::compareSchemas($oldSchema, $newSchema);
        } finally {
            $this->omitted = ['old' => [], 'new' => []];
        }

        $this->platform->registerRebuildPlan($diff, $this->plan($oldSchema, $newSchema, $diff, $oldOmitted, $newOmitted));

        return $diff;
    }

    /**
     * Compares the tables as generated DDL creates them; see
     * {@see self::asCreated()}.
     */
    public function compareTables(Table $oldTable, Table $newTable): TableDiff
    {
        return parent::compareTables(
            $this->asCreated($oldTable, $this->omitted['old']),
            $this->asCreated($newTable, $this->omitted['new']),
        );
    }

    /**
     * Refuses to rebuild tables with generated columns, naming the first such
     * column.
     *
     * @param array<string, Table> $tables The tables, keyed by {@see RebuildPlan::tableKey()}.
     *
     * @throws Exception
     */
    public static function assertNoGeneratedColumns(Connection $connection, array $tables): void
    {
        self::refuseGeneratedColumns($connection, $tables, self::readCurrentSchema($connection));
    }

    /**
     * Adds to each rebuild what DBAL cannot represent of its old table, so
     * that the rebuild recreates it: the indexes on expressions, which
     * introspection skips, the CHECK constraints other than the minimum and
     * maximum values of the old table's columns, and the columns' collations.
     *
     * @param array<string, Rebuild> $rebuilds The rebuilds, keyed by {@see RebuildPlan::tableKey()}.
     *
     * @return array<string, Rebuild>
     *
     * @throws Exception
     */
    public static function withCatalogDetails(Connection $connection, DuckDBPlatform $platform, array $rebuilds): array
    {
        if (count($rebuilds) === 0) {
            return $rebuilds;
        }

        return self::addCatalogDetails($connection, $platform, $rebuilds, self::readCurrentSchema($connection));
    }

    /**
     * Logs a notice for each collated column that the native ALTER statements
     * of a table diff give a type without collation, which drops the
     * collation.
     *
     * @param array<string, TableDiff> $diffs The natively altered tables' diffs, keyed by {@see RebuildPlan::tableKey()}.
     *
     * @throws Exception
     */
    public static function noticeDroppedCollations(Connection $connection, DuckDBPlatform $platform, array $diffs): void
    {
        self::logDroppedCollations($connection, $platform, $diffs, null);
    }

    /**
     * @param array<string, TableDiff> $diffs
     *
     * @throws Exception
     */
    private static function logDroppedCollations(
        Connection $connection,
        DuckDBPlatform $platform,
        array $diffs,
        mixed $currentSchema,
    ): void {
        $retyped = [];

        foreach ($diffs as $key => $diff) {
            foreach ($diff->getChangedColumns() as $columnDiff) {
                $new  = $columnDiff->getNewColumn();
                $type = Type::getType($new->getTypeName())->getSQLDeclaration($new->toArray(true), $platform);

                if (DuckDBPlatform::isCollatableType($type)) {
                    continue;
                }

                $retyped[$key][] = [$columnDiff->getOldColumn(), $type];
            }
        }

        if (count($retyped) === 0) {
            return;
        }

        $collations = self::readCollations($connection, $currentSchema ?? self::readCurrentSchema($connection));

        foreach ($retyped as $key => $columns) {
            foreach ($columns as [$old, $type]) {
                $collation = $collations[$key][RebuildPlan::columnKey($old)] ?? null;

                if ($collation === null) {
                    continue;
                }

                $platform->noticeDroppedCollation($diffs[$key]->getOldTable()->getObjectName(), $old, $collation, $type);
            }
        }
    }

    /**
     * Refuses to rebuild tables with generated columns, naming the first such
     * column.
     *
     * @param array<string, Table> $tables The tables, keyed by {@see RebuildPlan::tableKey()}.
     *
     * @throws Exception
     */
    private static function refuseGeneratedColumns(Connection $connection, array $tables, mixed $currentSchema): void
    {
        foreach (self::readGeneratedColumns($connection, $currentSchema) as $key => $column) {
            if (! isset($tables[$key])) {
                continue;
            }

            throw new NotSupported(sprintf(
                'Cannot rebuild table %s: its column %s.%s is a generated column, which DuckDB 2.0 reports like a '
                    . 'plain column with a default, so a rebuild would turn it into one.',
                $tables[$key]->getObjectName()->toString(),
                $tables[$key]->getObjectName()->getUnqualifiedName()->getValue(),
                $column,
            ));
        }
    }

    /**
     * Adds to each rebuild the indexes on expressions, the CHECK constraints
     * that are not a minimum or maximum value of the old table's columns, and
     * the column collations of its old table.
     *
     * @param array<string, Rebuild> $rebuilds The rebuilds, keyed by {@see RebuildPlan::tableKey()}.
     *
     * @return array<string, Rebuild>
     *
     * @throws Exception
     */
    private static function addCatalogDetails(
        Connection $connection,
        DuckDBPlatform $platform,
        array $rebuilds,
        mixed $currentSchema,
    ): array {
        $indexes    = self::readExpressionIndexes($connection, $platform, $currentSchema);
        $checks     = self::readChecks($connection, $currentSchema);
        $collations = self::readCollations($connection, $currentSchema);

        foreach ($rebuilds as $key => $rebuild) {
            $kept = [];

            foreach ($checks[$key] ?? [] as $check) {
                if (self::isColumnBound($rebuild['old'], $check)) {
                    continue;
                }

                $kept[] = $check;
            }

            $rebuilds[$key]['expressionIndexes'] = $indexes[$key] ?? [];
            $rebuilds[$key]['checks']            = $kept;
            $rebuilds[$key]['collations']        = $collations[$key] ?? [];
            $rebuilds[$key]['catalogRead']       = true;
        }

        return $rebuilds;
    }

    /**
     * Whether the CHECK constraint is the minimum or maximum value of a
     * column of the table, which the platform declares from the column.
     *
     * @param CatalogCheck $check
     */
    private static function isColumnBound(Table $table, array $check): bool
    {
        if (count($check['columns']) !== 1) {
            return false;
        }

        $bound = DuckDBMetadataProvider::parseBound($check['expression'], $check['columns'][0]);

        if ($bound === null) {
            return false;
        }

        foreach ($table->getColumns() as $column) {
            if (RebuildPlan::columnKey($column) !== strtolower($check['columns'][0])) {
                continue;
            }

            $value = $bound[0] === 'min' ? $column->getMinimumValue() : $column->getMaximumValue();

            return is_scalar($value) && (string) $value === (string) $bound[1];
        }

        return false;
    }

    /** @throws Exception */
    private static function readCurrentSchema(Connection $connection): mixed
    {
        return $connection->fetchOne('SELECT current_schema()');
    }

    protected function columnsEqual(Column $column1, Column $column2): bool
    {
        return parent::columnsEqual($column1, $column2)
            && $column1->getAutoincrement() === $column2->getAutoincrement()
            && AlterRouting::checksEqual($column1, $column2);
    }

    /**
     * @param array<string, true> $oldOmitted The keys the old schema leaves out to break cycles.
     * @param array<string, true> $newOmitted The keys the new schema leaves out to break cycles.
     *
     * @throws Exception
     */
    private function plan(
        Schema $oldSchema,
        Schema $newSchema,
        SchemaDiff $diff,
        array $oldOmitted,
        array $newOmitted,
    ): RebuildPlan {
        $oldTables     = RebuildPlan::byKey(array_map(
            fn (Table $table): Table => $this->asCreated($table, $oldOmitted),
            $oldSchema->getTables(),
        ));
        $newTables     = RebuildPlan::byKey(array_map(
            fn (Table $table): Table => $this->asCreated($table, $newOmitted),
            $newSchema->getTables(),
        ));
        $createdTables = [];
        $referencedBy  = RebuildPlan::referencedBy($oldTables);

        foreach ($diff->getCreatedTables() as $table) {
            $createdTables[RebuildPlan::tableKey($table->getObjectName())] = $this->asCreated($table, $newOmitted);
        }

        $currentSchema = $this->connection?->fetchOne('SELECT current_schema()');
        $constraints   = null;
        $routing       = new AlterRouting($this->platform);
        $diffs         = [];
        $native        = [];
        $rebuild       = [];

        foreach ($diff->getAlteredTables() as $tableDiff) {
            $key = RebuildPlan::tableKey($tableDiff->getOldTable()->getObjectName());

            $constraints ??= $this->readKeyConstraints($currentSchema);

            $sql = $routing->nativeSQL(
                $tableDiff,
                isset($referencedBy[$key]),
                $constraints[$key]['unique'] ?? [],
            );

            $diffs[$key] = $tableDiff;

            if ($sql === null) {
                $rebuild[$key] = true;

                continue;
            }

            $native[$key] = $sql;
        }

        $newForeignKeys = [];

        foreach ($createdTables as $table) {
            $newForeignKeys = array_merge($newForeignKeys, $table->getForeignKeys());
        }

        foreach ($diffs as $tableDiff) {
            $newForeignKeys = array_merge(
                $newForeignKeys,
                $tableDiff->getAddedForeignKeys(),
                $tableDiff->getModifiedForeignKeys(),
            );
        }

        foreach ($this->createdForeignKeys($newForeignKeys) as $foreignKey) {
            $parent = RebuildPlan::tableKey($foreignKey->getReferencedTableName());

            if (
                isset($createdTables[$parent])
                || isset($rebuild[$parent])
                || ! isset($oldTables[$parent], $newTables[$parent])
            ) {
                continue;
            }

            $constraints ??= $this->readKeyConstraints($currentSchema);

            if ($this->hasKeyConstraint($oldTables, $parent, $foreignKey, $constraints)) {
                continue;
            }

            $rebuild[$parent] = true;
        }

        $rebuild  = RebuildPlan::referencingClosure($rebuild, $referencedBy, $newTables);
        $rebuilds = [];

        foreach (array_keys($rebuild) as $key) {
            unset($native[$key]);

            $rebuilds[$key] = RebuildPlan::rebuild($oldTables[$key], $newTables[$key], $diffs[$key] ?? null);
        }

        if ($this->connection !== null) {
            $rebuilt = [];

            foreach ($rebuilds as $key => $entry) {
                $rebuilt[$key] = $entry['old'];
            }

            self::refuseGeneratedColumns($this->connection, $rebuilt, $currentSchema);

            if (count($rebuilds) > 0) {
                $rebuilds = self::addCatalogDetails($this->connection, $this->platform, $rebuilds, $currentSchema);
            }

            self::logDroppedCollations(
                $this->connection,
                $this->platform,
                array_intersect_key($diffs, $native),
                $currentSchema,
            );
        }

        return new RebuildPlan($native, $rebuilds, $newOmitted, $oldOmitted);
    }

    /**
     * Whether the referenced table has a PRIMARY KEY or UNIQUE constraint on
     * the referenced columns: in the database, or, without one, as its
     * primary key or a column set an existing foreign key references.
     *
     * @param array<string, Table>                                              $oldTables
     * @param array<string, array{unique: list<string>, primary: list<string>}> $constraints
     */
    private function hasKeyConstraint(
        array $oldTables,
        string $parent,
        ForeignKeyConstraint $foreignKey,
        array $constraints,
    ): bool {
        $columnSet = RebuildPlan::columnSetKey($foreignKey->getReferencedColumnNames());

        if (isset($constraints[$parent])) {
            return in_array($columnSet, $constraints[$parent]['unique'], true)
                || in_array($columnSet, $constraints[$parent]['primary'], true);
        }

        $primaryKey = $oldTables[$parent]->getPrimaryKeyConstraint();

        if ($primaryKey !== null && RebuildPlan::columnSetKey($primaryKey->getColumnNames()) === $columnSet) {
            return true;
        }

        foreach ($oldTables as $table) {
            foreach ($table->getForeignKeys() as $existing) {
                if (
                    RebuildPlan::tableKey($existing->getReferencedTableName()) === $parent
                    && RebuildPlan::columnSetKey($existing->getReferencedColumnNames()) === $columnSet
                ) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The foreign keys that generated DDL creates.
     *
     * @param array<ForeignKeyConstraint> $foreignKeys
     *
     * @return list<ForeignKeyConstraint>
     */
    private function createdForeignKeys(array $foreignKeys): array
    {
        $created = [];

        foreach ($foreignKeys as $foreignKey) {
            if (! $this->platform->createsForeignKey($foreignKey)) {
                continue;
            }

            $created[] = $foreignKey;
        }

        return $created;
    }

    /**
     * The table as generated DDL creates it and introspection reports it.
     *
     * Its foreign keys lose those that generated DDL leaves out, and the given
     * ones, while the indexes that backed them stay, as generated DDL creates
     * them too.
     *
     * A composite foreign key whose columns another index lists in a different
     * order takes that index's order, and the index DBAL implicitly added for
     * the key's own order goes. The platform lists a key's columns in the
     * order of the referenced key, which can differ from the declared order
     * of the index backing it, so introspection reports the key in another
     * order than that index, and DBAL then adds an index the database lacks.
     *
     * @param array<string, true> $omitted Keyed by {@see ForeignKeyCycles::identity()}.
     */
    private function asCreated(Table $table, array $omitted): Table
    {
        $foreignKeys     = [];
        $implicitIndexes = [];
        $changed         = false;

        foreach ($table->getForeignKeys() as $foreignKey) {
            if (
                ! $this->platform->createsForeignKey($foreignKey)
                || isset($omitted[ForeignKeyCycles::identity($table->getObjectName(), $foreignKey)])
            ) {
                $changed = true;

                continue;
            }

            $ordered = self::inIndexOrder($foreignKey, $table);

            if ($ordered !== $foreignKey) {
                $changed = true;

                $implicitIndexes[self::columnListKey($foreignKey->getReferencingColumnNames())] = true;
            }

            $foreignKeys[] = $ordered;
        }

        if (! $changed) {
            return $table;
        }

        $explicitIndexes = $table->edit()->setForeignKeyConstraints()->create()->getIndexes();
        $indexes         = [];

        foreach ($table->getIndexes() as $name => $index) {
            if ($index->isPrimary()) {
                continue;
            }

            if (! isset($explicitIndexes[$name]) && isset($implicitIndexes[self::indexColumnListKey($index)])) {
                continue;
            }

            $indexes[] = $index;
        }

        return $table->edit()
            ->setIndexes(...$indexes)
            ->setForeignKeyConstraints(...$foreignKeys)
            ->create();
    }

    /**
     * The foreign key with its column pairs in the order of another index of
     * the table on the same referencing columns, or the key as it is when the
     * table has no such index.
     */
    private static function inIndexOrder(ForeignKeyConstraint $foreignKey, Table $table): ForeignKeyConstraint
    {
        $referencingColumns = $foreignKey->getReferencingColumnNames();
        $referencedColumns  = $foreignKey->getReferencedColumnNames();

        if (count($referencingColumns) < 2 || count($referencingColumns) !== count($referencedColumns)) {
            return $foreignKey;
        }

        $positions = [];

        foreach ($referencingColumns as $position => $column) {
            $positions[strtolower($column->getIdentifier()->getValue())] = $position;
        }

        $keyOrder = self::columnListKey($referencingColumns);

        foreach ($table->getIndexes() as $index) {
            $indexOrder = self::indexColumnListKey($index);

            if ($index->isPrimary() || $indexOrder === $keyOrder) {
                continue;
            }

            $order = [];

            foreach ($index->getIndexedColumns() as $column) {
                $order[] = $positions[strtolower($column->getColumnName()->getIdentifier()->getValue())] ?? null;
            }

            if (count($order) !== count($positions) || in_array(null, $order, true)) {
                continue;
            }

            return RebuildPlan::reorderForeignKey($foreignKey, $order);
        }

        return $foreignKey;
    }

    /** @param list<UnqualifiedName> $columns */
    private static function columnListKey(array $columns): string
    {
        return implode("\0", array_map(
            static fn (UnqualifiedName $column): string => strtolower($column->getIdentifier()->getValue()),
            $columns,
        ));
    }

    private static function indexColumnListKey(Index $index): string
    {
        return self::columnListKey(array_map(
            static fn (IndexedColumn $column): UnqualifiedName => $column->getColumnName(),
            $index->getIndexedColumns(),
        ));
    }

    /**
     * The column-set keys of each table's UNIQUE and PRIMARY KEY constraints,
     * keyed by {@see RebuildPlan::tableKey()}.
     *
     * @return array<string, array{unique: list<string>, primary: list<string>}>
     *
     * @throws Exception
     */
    private function readKeyConstraints(mixed $currentSchema): array
    {
        if ($this->connection === null) {
            return [];
        }

        $constraints = [];

        $rows = $this->connection->fetchAllNumeric(<<<'SQL'
            SELECT schema_name, table_name, constraint_type, constraint_column_names
            FROM duckdb_constraints()
            WHERE database_name = current_database()
              AND constraint_type IN ('PRIMARY KEY', 'UNIQUE')
            SQL);

        foreach ($rows as [$schema, $table, $type, $columns]) {
            if (! is_string($schema) || ! is_string($table) || ! is_array($columns)) {
                continue;
            }

            $key  = self::catalogKey($schema, $table, $currentSchema);
            $kind = $type === 'UNIQUE' ? 'unique' : 'primary';

            $constraints[$key] ??= ['unique' => [], 'primary' => []];

            $names = [];

            foreach ($columns as $column) {
                if (! is_string($column)) {
                    continue;
                }

                $names[] = $column;
            }

            $constraints[$key][$kind][] = RebuildPlan::columnSetKey($names);
        }

        return $constraints;
    }

    /**
     * The first generated column of each table, keyed by
     * {@see RebuildPlan::tableKey()}.
     *
     * @return array<string, string>
     *
     * @throws Exception
     */
    private static function readGeneratedColumns(Connection $connection, mixed $currentSchema): array
    {
        $columns = [];

        $rows = $connection->fetchAllNumeric(<<<'SQL'
            SELECT schema_name, table_name, column_name
            FROM duckdb_columns()
            WHERE database_name = current_database()
              AND is_generated
            ORDER BY schema_name, table_name, column_index
            SQL);

        foreach ($rows as [$schema, $table, $column]) {
            if (! is_string($schema) || ! is_string($table) || ! is_string($column)) {
                continue;
            }

            $columns[self::catalogKey($schema, $table, $currentSchema)] ??= $column;
        }

        return $columns;
    }

    /**
     * The indexes on expressions of each table, keyed by
     * {@see RebuildPlan::tableKey()}: the indexes of duckdb_indexes() that
     * introspection skips.
     *
     * @return array<string, list<ExpressionIndex>>
     *
     * @throws Exception
     */
    private static function readExpressionIndexes(
        Connection $connection,
        DuckDBPlatform $platform,
        mixed $currentSchema,
    ): array {
        $currentSchemaName = is_string($currentSchema) && $currentSchema !== '' ? $currentSchema : null;
        $introspected      = [];

        foreach ($platform->createMetadataProvider($connection)->fetchIndexes(null, null, $currentSchemaName) as $index) {
            $introspected[$index['schema'] . "\0" . $index['table'] . "\0" . $index['name']] = true;
        }

        $indexes = [];

        $rows = $connection->fetchAllNumeric(<<<'SQL'
            SELECT schema_name, table_name, index_name, is_unique, sql
            FROM duckdb_indexes()
            WHERE database_name = current_database()
            ORDER BY schema_name, table_name, index_name
            SQL);

        foreach ($rows as [$schema, $table, $name, $unique, $sql]) {
            if (! is_string($schema) || ! is_string($table) || ! is_string($name) || $name === '' || ! is_string($sql)) {
                continue;
            }

            if (isset($introspected[$schema . "\0" . $table . "\0" . $name])) {
                continue;
            }

            $indexes[self::catalogKey($schema, $table, $currentSchema)][] = [
                'schema' => $schema,
                'table' => $table,
                'name' => $name,
                'unique' => $unique === true,
                'sql' => $sql,
            ];
        }

        return $indexes;
    }

    /**
     * The CHECK constraints of each table, keyed by
     * {@see RebuildPlan::tableKey()}, in declaration order.
     *
     * @return array<string, list<CatalogCheck>>
     *
     * @throws Exception
     */
    private static function readChecks(Connection $connection, mixed $currentSchema): array
    {
        $checks = [];

        $rows = $connection->fetchAllNumeric(<<<'SQL'
            SELECT schema_name, table_name, constraint_name, expression, constraint_column_names
            FROM duckdb_constraints()
            WHERE database_name = current_database()
              AND constraint_type = 'CHECK'
            ORDER BY schema_name, table_name, constraint_index
            SQL);

        foreach ($rows as [$schema, $table, $name, $expression, $columns]) {
            if (! is_string($schema) || ! is_string($table) || ! is_string($expression) || ! is_array($columns)) {
                continue;
            }

            $names = [];

            foreach ($columns as $column) {
                if (! is_string($column)) {
                    continue;
                }

                $names[] = $column;
            }

            $checks[self::catalogKey($schema, $table, $currentSchema)][] = [
                'name' => is_string($name) ? $name : '',
                'expression' => $expression,
                'columns' => $names,
            ];
        }

        return $checks;
    }

    /**
     * The collation of each collated column, keyed by
     * {@see RebuildPlan::tableKey()} and by the column's name in lower case.
     *
     * DuckDB reports a collation only in the CREATE TABLE statement of
     * duckdb_tables(), right after the column's VARCHAR type.
     *
     * @return array<string, array<string, string>>
     *
     * @throws Exception
     */
    private static function readCollations(Connection $connection, mixed $currentSchema): array
    {
        $collations = [];

        $rows = $connection->fetchAllNumeric(<<<'SQL'
            SELECT schema_name, table_name, sql
            FROM duckdb_tables()
            WHERE database_name = current_database()
              AND sql LIKE '% COLLATE %'
            SQL);

        foreach ($rows as [$schema, $table, $sql]) {
            if (! is_string($schema) || ! is_string($table) || ! is_string($sql)) {
                continue;
            }

            $columns = self::collatedColumns($sql);

            if (count($columns) === 0) {
                continue;
            }

            $collations[self::catalogKey($schema, $table, $currentSchema)] = $columns;
        }

        return $collations;
    }

    /**
     * The collated columns of a CREATE TABLE statement as DuckDB's catalog
     * reports it, `CREATE TABLE name(column VARCHAR COLLATE collation ..., ...);`,
     * keyed by the column's name in lower case.
     *
     * @return array<string, string>
     */
    private static function collatedColumns(string $sql): array
    {
        $collations = [];

        foreach (self::tableElements($sql) as $element) {
            if (
                preg_match(
                    '/^(?:"((?:[^"]|"")+)"|([^\s"]+)) VARCHAR COLLATE ([^\s,()]+)/',
                    $element,
                    $matches,
                ) !== 1
            ) {
                continue;
            }

            $name = $matches[1] !== '' ? str_replace('""', '"', $matches[1]) : $matches[2];

            $collations[strtolower($name)] = $matches[3];
        }

        return $collations;
    }

    /**
     * The comma-separated elements between the outermost parentheses of a
     * CREATE TABLE statement: its column definitions and table constraints.
     *
     * @return list<string>
     */
    private static function tableElements(string $sql): array
    {
        $elements = [];
        $depth    = 0;
        $quote    = null;
        $start    = 0;
        $length   = strlen($sql);

        for ($position = 0; $position < $length; $position++) {
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

            if ($character === '(') {
                $depth++;

                if ($depth === 1) {
                    $start = $position + 1;
                }

                continue;
            }

            if ($character === ')') {
                $depth--;

                if ($depth === 0) {
                    $elements[] = trim(substr($sql, $start, $position - $start));

                    break;
                }

                continue;
            }

            if ($character !== ',' || $depth !== 1) {
                continue;
            }

            $elements[] = trim(substr($sql, $start, $position - $start));
            $start      = $position + 1;
        }

        return $elements;
    }

    /** The {@see RebuildPlan::tableKey()} of a table DuckDB's catalog names. */
    private static function catalogKey(string $schema, string $table, mixed $currentSchema): string
    {
        if ($schema === $currentSchema) {
            return strtolower($table);
        }

        return strtolower($schema) . '.' . strtolower($table);
    }
}
