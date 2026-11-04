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
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\SchemaDiff;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Schema\TableDiff;
use MartinJuul\DBAL\DuckDB\Platform\DuckDBPlatform;

use function array_keys;
use function array_merge;
use function array_pop;
use function count;
use function in_array;
use function is_array;
use function is_string;
use function sprintf;
use function strtolower;

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
 * When the platform omits foreign keys, the comparison ignores them, so a diff
 * never proposes adding one.
 *
 * With a connection, each rebuild also carries the indexes on expressions of
 * its table, which DBAL cannot represent, so that the rebuild recreates them.
 *
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

    /** @throws Exception */
    public function compareSchemas(Schema $oldSchema, Schema $newSchema): SchemaDiff
    {
        $diff = parent::compareSchemas($oldSchema, $newSchema);

        if ($this->platform->omitsForeignKeys()) {
            $diff = self::withoutForeignKeyChanges($diff);
        }

        $this->platform->registerRebuildPlan($diff, $this->plan($oldSchema, $newSchema, $diff));

        return $diff;
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
        foreach (self::readGeneratedColumns($connection) as $key => $column) {
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
     * Adds to each rebuild the indexes on expressions of its old table, which
     * introspection skips because DBAL cannot represent them, so that the
     * rebuild recreates them.
     *
     * @param array<string, Rebuild> $rebuilds The rebuilds, keyed by {@see RebuildPlan::tableKey()}.
     *
     * @return array<string, Rebuild>
     *
     * @throws Exception
     */
    public static function withExpressionIndexes(Connection $connection, DuckDBPlatform $platform, array $rebuilds): array
    {
        if (count($rebuilds) === 0) {
            return $rebuilds;
        }

        $indexes = self::readExpressionIndexes($connection, $platform);

        foreach (array_keys($rebuilds) as $key) {
            $rebuilds[$key]['expressionIndexes'] = $indexes[$key] ?? [];
        }

        return $rebuilds;
    }

    protected function columnsEqual(Column $column1, Column $column2): bool
    {
        return parent::columnsEqual($column1, $column2)
            && $column1->getAutoincrement() === $column2->getAutoincrement()
            && AlterRouting::checksEqual($column1, $column2);
    }

    /** @throws Exception */
    private function plan(Schema $oldSchema, Schema $newSchema, SchemaDiff $diff): RebuildPlan
    {
        $omitForeignKeys = $this->platform->omitsForeignKeys();
        $oldTables       = self::byKey($oldSchema->getTables());
        $newTables       = self::byKey($newSchema->getTables());
        $createdTables   = self::byKey($diff->getCreatedTables());
        $referencedBy    = [];

        if (! $omitForeignKeys) {
            foreach ($oldTables as $key => $table) {
                foreach ($table->getForeignKeys() as $foreignKey) {
                    $referencedBy[RebuildPlan::tableKey($foreignKey->getReferencedTableName())][$key] = true;
                }
            }
        }

        $constraints = $this->connection === null ? [] : self::readKeyConstraints($this->connection);
        $routing     = new AlterRouting($this->platform);
        $diffs       = [];
        $native      = [];
        $rebuild     = [];

        foreach ($diff->getAlteredTables() as $tableDiff) {
            $key = RebuildPlan::tableKey($tableDiff->getOldTable()->getObjectName());
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

        if (! $omitForeignKeys) {
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

            foreach ($newForeignKeys as $foreignKey) {
                $parent = RebuildPlan::tableKey($foreignKey->getReferencedTableName());

                if (
                    isset($createdTables[$parent])
                    || isset($rebuild[$parent])
                    || ! isset($oldTables[$parent], $newTables[$parent])
                    || $this->hasKeyConstraint($oldTables, $parent, $foreignKey, $constraints)
                ) {
                    continue;
                }

                $rebuild[$parent] = true;
            }
        }

        $queue = array_keys($rebuild);

        while (count($queue) > 0) {
            $key = array_pop($queue);

            foreach (array_keys($referencedBy[$key] ?? []) as $child) {
                if (isset($rebuild[$child]) || ! isset($newTables[$child])) {
                    continue;
                }

                $rebuild[$child] = true;
                $queue[]         = $child;
            }
        }

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

            self::assertNoGeneratedColumns($this->connection, $rebuilt);

            $rebuilds = self::withExpressionIndexes($this->connection, $this->platform, $rebuilds);
        }

        return new RebuildPlan($native, $rebuilds);
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
     * @param array<Table> $tables
     *
     * @return array<string, Table>
     */
    private static function byKey(array $tables): array
    {
        $byKey = [];

        foreach ($tables as $table) {
            $byKey[RebuildPlan::tableKey($table->getObjectName())] = $table;
        }

        return $byKey;
    }

    private static function withoutForeignKeyChanges(SchemaDiff $diff): SchemaDiff
    {
        $alteredTables = [];

        foreach ($diff->getAlteredTables() as $tableDiff) {
            $alteredTables[] = new TableDiff(
                $tableDiff->getOldTable(),
                addedColumns: $tableDiff->getAddedColumns(),
                changedColumns: $tableDiff->getChangedColumns(),
                droppedColumns: $tableDiff->getDroppedColumns(),
                addedIndexes: $tableDiff->getAddedIndexes(),
                modifiedIndexes: $tableDiff->getModifiedIndexes(),
                droppedIndexes: $tableDiff->getDroppedIndexes(),
                renamedIndexes: $tableDiff->getRenamedIndexes(),
            );
        }

        return new SchemaDiff(
            $diff->getCreatedSchemas(),
            $diff->getDroppedSchemas(),
            $diff->getCreatedTables(),
            $alteredTables,
            $diff->getDroppedTables(),
            $diff->getCreatedSequences(),
            $diff->getAlteredSequences(),
            $diff->getDroppedSequences(),
        );
    }

    /**
     * The column-set keys of each table's UNIQUE and PRIMARY KEY constraints,
     * keyed by {@see RebuildPlan::tableKey()}.
     *
     * @return array<string, array{unique: list<string>, primary: list<string>}>
     *
     * @throws Exception
     */
    private static function readKeyConstraints(Connection $connection): array
    {
        $currentSchema = $connection->fetchOne('SELECT current_schema()');
        $constraints   = [];

        $rows = $connection->fetchAllNumeric(<<<'SQL'
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
    private static function readGeneratedColumns(Connection $connection): array
    {
        $currentSchema = $connection->fetchOne('SELECT current_schema()');
        $columns       = [];

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
    private static function readExpressionIndexes(Connection $connection, DuckDBPlatform $platform): array
    {
        $introspected = [];

        foreach ($platform->createMetadataProvider($connection)->fetchIndexes(null, null) as $index) {
            $introspected[$index['schema'] . "\0" . $index['table'] . "\0" . $index['name']] = true;
        }

        $currentSchema = $connection->fetchOne('SELECT current_schema()');
        $indexes       = [];

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

    /** The {@see RebuildPlan::tableKey()} of a table DuckDB's catalog names. */
    private static function catalogKey(string $schema, string $table, mixed $currentSchema): string
    {
        if ($schema === $currentSchema) {
            return strtolower($table);
        }

        return strtolower($schema) . '.' . strtolower($table);
    }
}
