<?php

declare(strict_types=1);

namespace MartinJuul\DBAL\DuckDB\Schema;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Result;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\DefaultExpression\CurrentDate;
use Doctrine\DBAL\Schema\DefaultExpression\CurrentTime;
use Doctrine\DBAL\Schema\DefaultExpression\CurrentTimestamp;
use Doctrine\DBAL\Schema\ForeignKeyConstraint\MatchType;
use Doctrine\DBAL\Schema\ForeignKeyConstraint\ReferentialAction;
use Doctrine\DBAL\Schema\Index\IndexType;
use Doctrine\DBAL\Schema\Metadata\DatabaseMetadataRow;
use Doctrine\DBAL\Schema\Metadata\ForeignKeyConstraintColumnMetadataRow;
use Doctrine\DBAL\Schema\Metadata\IndexColumnMetadataRow;
use Doctrine\DBAL\Schema\Metadata\MetadataProvider;
use Doctrine\DBAL\Schema\Metadata\PrimaryKeyConstraintColumnRow;
use Doctrine\DBAL\Schema\Metadata\SchemaMetadataRow;
use Doctrine\DBAL\Schema\Metadata\SequenceMetadataRow;
use Doctrine\DBAL\Schema\Metadata\TableColumnMetadataRow;
use Doctrine\DBAL\Schema\Metadata\TableMetadataRow;
use Doctrine\DBAL\Schema\Metadata\UniqueConstraintColumnMetadataRow;
use Doctrine\DBAL\Schema\Metadata\ViewMetadataRow;
use Doctrine\DBAL\Schema\Name\Identifier;
use Doctrine\DBAL\Schema\Name\OptionallyQualifiedName;
use Doctrine\DBAL\Types\BooleanType;
use Doctrine\DBAL\Types\PhpDateMappingType;
use Doctrine\DBAL\Types\PhpDateTimeMappingType;
use Doctrine\DBAL\Types\PhpTimeMappingType;
use Doctrine\DBAL\Types\Type;
use MartinJuul\DBAL\DuckDB\Platform\DuckDBPlatform;
use MartinJuul\DBAL\DuckDB\Types\ListType;
use MartinJuul\DBAL\DuckDB\Types\MapType;
use MartinJuul\DBAL\DuckDB\Types\NativeType;
use MartinJuul\DBAL\DuckDB\Types\StructType;
use UnexpectedValueException;

use function array_map;
use function count;
use function crc32;
use function dechex;
use function get_debug_type;
use function implode;
use function in_array;
use function is_array;
use function is_numeric;
use function is_string;
use function preg_match;
use function sort;
use function sprintf;
use function str_contains;
use function str_replace;
use function str_starts_with;
use function strcasecmp;
use function strlen;
use function strtolower;
use function strtoupper;
use function substr;
use function trim;
use function usort;

/**
 * Reads DuckDB's catalog functions for DBAL's schema introspection.
 *
 * Only objects of the current database are reported: the temporary catalog,
 * attached databases, internal objects and nested schemas are skipped.
 *
 * DuckDB discards constraint names, so names follow DBAL's conventions: a
 * UNIQUE constraint, which the platform declares inline only for column sets
 * that a foreign key references, is reported as a unique index named the way
 * DBAL names an unnamed unique index, and a foreign key is named the way DBAL
 * names an unnamed foreign key. Unique constraints themselves are never
 * reported, because DBAL's comparator compares only indexes.
 *
 * A column whose default draws from an owned sequence (see
 * {@see DuckDBPlatform::OWNED_SEQUENCE_SUFFIX}) is reported as an
 * `autoincrement` column without a default, and the owned sequences are not
 * reported as sequences.
 *
 * The public methods that are not part of {@see MetadataProvider} serve
 * {@see DuckDBSchemaManager} and are not a stable API.
 */
final readonly class DuckDBMetadataProvider implements MetadataProvider
{
    /** @internal This class can be instantiated only by the DuckDB platform. */
    public function __construct(private Connection $connection, private DuckDBPlatform $platform)
    {
    }

    /**
     * The paths of the file databases attached to the connection. DuckDB
     * names a database by its file, which {@see DuckDBSchemaManager::createDatabase()}
     * and {@see DuckDBSchemaManager::dropDatabase()} take.
     *
     * {@inheritDoc}
     */
    public function getAllDatabaseNames(): iterable
    {
        foreach ($this->fetchDatabasePaths() as $path) {
            yield new DatabaseMetadataRow($path);
        }
    }

    /** {@inheritDoc} */
    public function getAllSchemaNames(): iterable
    {
        foreach ($this->fetchSchemaNames() as $schemaName) {
            yield new SchemaMetadataRow($schemaName);
        }
    }

    /** {@inheritDoc} */
    public function getAllTableNames(): iterable
    {
        foreach ($this->fetchTables(null, null) as $table) {
            yield new TableMetadataRow($table['schema'], $table['table'], []);
        }
    }

    /** {@inheritDoc} */
    public function getTableColumnsForAllTables(): iterable
    {
        return $this->getTableColumns(null, null);
    }

    /** {@inheritDoc} */
    public function getTableColumnsForTable(string|null $schemaName, string $tableName): iterable
    {
        return $this->getTableColumns($schemaName, $tableName);
    }

    /** @return iterable<TableColumnMetadataRow> */
    private function getTableColumns(string|null $schemaName, string|null $tableName): iterable
    {
        foreach ($this->fetchColumns($schemaName, $tableName) as $column) {
            yield new TableColumnMetadataRow($column['schema'], $column['table'], $column['column']);
        }
    }

    /** {@inheritDoc} */
    public function getIndexColumnsForAllTables(): iterable
    {
        return $this->getIndexColumns(null, null);
    }

    /** {@inheritDoc} */
    public function getIndexColumnsForTable(string|null $schemaName, string $tableName): iterable
    {
        return $this->getIndexColumns($schemaName, $tableName);
    }

    /** @return iterable<IndexColumnMetadataRow> */
    private function getIndexColumns(string|null $schemaName, string|null $tableName): iterable
    {
        foreach ($this->fetchIndexes($schemaName, $tableName) as $index) {
            foreach ($index['columns'] as $column) {
                yield new IndexColumnMetadataRow(
                    schemaName: $index['schema'],
                    tableName: $index['table'],
                    indexName: $index['name'],
                    type: $index['unique'] ? IndexType::UNIQUE : IndexType::REGULAR,
                    isClustered: false,
                    predicate: null,
                    columnName: $column,
                    columnLength: null,
                );
            }
        }
    }

    /** {@inheritDoc} */
    public function getPrimaryKeyConstraintColumnsForAllTables(): iterable
    {
        return $this->getPrimaryKeyConstraintColumns(null, null);
    }

    /** {@inheritDoc} */
    public function getPrimaryKeyConstraintColumnsForTable(string|null $schemaName, string $tableName): iterable
    {
        return $this->getPrimaryKeyConstraintColumns($schemaName, $tableName);
    }

    /** @return iterable<PrimaryKeyConstraintColumnRow> */
    private function getPrimaryKeyConstraintColumns(string|null $schemaName, string|null $tableName): iterable
    {
        foreach ($this->fetchPrimaryKeys($schemaName, $tableName) as $primaryKey) {
            foreach ($primaryKey['columns'] as $column) {
                yield new PrimaryKeyConstraintColumnRow(
                    schemaName: $primaryKey['schema'],
                    tableName: $primaryKey['table'],
                    constraintName: null,
                    isClustered: true,
                    columnName: $column,
                );
            }
        }
    }

    /**
     * UNIQUE constraints are reported as unique indexes instead.
     *
     * @return iterable<UniqueConstraintColumnMetadataRow>
     */
    public function getUniqueConstraintColumnsForAllTables(): iterable
    {
        return [];
    }

    /**
     * UNIQUE constraints are reported as unique indexes instead.
     *
     * @param ?non-empty-string $schemaName
     * @param non-empty-string  $tableName
     *
     * @return iterable<UniqueConstraintColumnMetadataRow>
     */
    public function getUniqueConstraintColumnsForTable(string|null $schemaName, string $tableName): iterable
    {
        return [];
    }

    /** {@inheritDoc} */
    public function getForeignKeyConstraintColumnsForAllTables(): iterable
    {
        return $this->getForeignKeyConstraintColumns(null, null);
    }

    /** {@inheritDoc} */
    public function getForeignKeyConstraintColumnsForTable(string|null $schemaName, string $tableName): iterable
    {
        return $this->getForeignKeyConstraintColumns($schemaName, $tableName);
    }

    /** @return iterable<ForeignKeyConstraintColumnMetadataRow> */
    private function getForeignKeyConstraintColumns(string|null $schemaName, string|null $tableName): iterable
    {
        foreach ($this->fetchForeignKeys($schemaName, $tableName) as $foreignKey) {
            foreach ($foreignKey['columns'] as $position => $column) {
                yield new ForeignKeyConstraintColumnMetadataRow(
                    $foreignKey['schema'],
                    $foreignKey['table'],
                    $foreignKey['id'],
                    $foreignKey['name'],
                    $foreignKey['schema'],
                    $foreignKey['referencedTable'],
                    MatchType::SIMPLE,
                    ReferentialAction::NO_ACTION,
                    ReferentialAction::NO_ACTION,
                    false,
                    false,
                    $column,
                    $foreignKey['referencedColumns'][$position],
                );
            }
        }
    }

    /** {@inheritDoc} */
    public function getTableOptionsForAllTables(): iterable
    {
        return $this->getTableOptions(null, null);
    }

    /** {@inheritDoc} */
    public function getTableOptionsForTable(string|null $schemaName, string $tableName): iterable
    {
        return $this->getTableOptions($schemaName, $tableName);
    }

    /** @return iterable<TableMetadataRow> */
    private function getTableOptions(string|null $schemaName, string|null $tableName): iterable
    {
        foreach ($this->fetchTables($schemaName, $tableName) as $table) {
            yield new TableMetadataRow($table['schema'], $table['table'], $table['options']);
        }
    }

    /** {@inheritDoc} */
    public function getAllViews(): iterable
    {
        foreach ($this->fetchViews() as $view) {
            yield new ViewMetadataRow($view['schema'], $view['name'], $view['definition']);
        }
    }

    /** {@inheritDoc} */
    public function getAllSequences(): iterable
    {
        foreach ($this->fetchSequences() as $sequence) {
            yield new SequenceMetadataRow(
                schemaName: $sequence['schema'],
                sequenceName: $sequence['name'],
                allocationSize: $sequence['allocationSize'],
                initialValue: $sequence['initialValue'],
                cacheSize: null,
            );
        }
    }

    /**
     * @return non-empty-string
     *
     * @throws Exception
     */
    public function fetchCurrentSchemaName(): string
    {
        return self::nonEmptyString($this->connection->fetchOne('SELECT current_schema()'));
    }

    /**
     * @return list<non-empty-string>
     *
     * @throws Exception
     */
    public function fetchDatabasePaths(): array
    {
        $sql = <<<'SQL'
            SELECT path
            FROM duckdb_databases()
            WHERE NOT internal
              AND path IS NOT NULL
            ORDER BY path
            SQL;

        return array_map(self::nonEmptyString(...), $this->connection->fetchFirstColumn($sql));
    }

    /**
     * The schemas of the current database, including the default schema.
     *
     * @return list<non-empty-string>
     *
     * @throws Exception
     */
    public function fetchSchemaNames(): array
    {
        $sql = <<<'SQL'
            SELECT schema_name
            FROM duckdb_schemas()
            WHERE database_name = current_database()
              AND parent_schema IS NULL
            ORDER BY schema_name
            SQL;

        return array_map(self::nonEmptyString(...), $this->connection->fetchFirstColumn($sql));
    }

    /**
     * Selects the schema, name and comment of the base tables.
     *
     * @throws Exception
     */
    public function selectTables(string|null $schemaName, string|null $tableName): Result
    {
        $params = [];
        $where  = $this->buildTablePredicate($schemaName, $tableName, $params);

        $sql = <<<SQL
            SELECT t.schema_name,
                   t.table_name,
                   t.comment
            FROM duckdb_tables() t
            JOIN duckdb_schemas() s ON s.oid = t.schema_oid
            WHERE {$where}
            ORDER BY t.schema_name,
                     t.table_name
            SQL;

        return $this->connection->executeQuery($sql, $params);
    }

    /**
     * @return list<array{
     *     schema: non-empty-string,
     *     table: non-empty-string,
     *     options: array<non-empty-string, mixed>,
     * }>
     *
     * @throws Exception
     */
    public function fetchTables(string|null $schemaName, string|null $tableName): array
    {
        $tables = [];

        foreach ($this->selectTables($schemaName, $tableName)->iterateNumeric() as [$schema, $table, $comment]) {
            $options = [];

            if (is_string($comment) && $comment !== '') {
                $options['comment'] = $comment;
            }

            $tables[] = [
                'schema' => self::nonEmptyString($schema),
                'table' => self::nonEmptyString($table),
                'options' => $options,
            ];
        }

        return $tables;
    }

    /**
     * Selects the catalog rows of the table columns.
     *
     * @throws Exception
     */
    public function selectColumns(string|null $schemaName, string|null $tableName): Result
    {
        $params = [];
        $where  = $this->buildTablePredicate($schemaName, $tableName, $params);

        $sql = <<<SQL
            SELECT c.schema_name,
                   c.table_name,
                   c.column_name,
                   c.data_type,
                   c.is_nullable,
                   c.column_default,
                   c.comment
            FROM duckdb_columns() c
            JOIN duckdb_tables() t ON t.table_oid = c.table_oid
            JOIN duckdb_schemas() s ON s.oid = t.schema_oid
            WHERE {$where}
            ORDER BY c.schema_name,
                     c.table_name,
                     c.column_index
            SQL;

        return $this->connection->executeQuery($sql, $params);
    }

    /**
     * @return list<array{schema: non-empty-string, table: non-empty-string, column: Column}>
     *
     * @throws Exception
     */
    public function fetchColumns(string|null $schemaName, string|null $tableName): array
    {
        $columns = [];
        $bounds  = $this->fetchBounds($schemaName, $tableName);

        foreach ($this->selectColumns($schemaName, $tableName)->iterateNumeric() as $row) {
            [$schema, $table, $name, $dataType, $nullable, $default, $comment] = $row;

            $schema = self::nonEmptyString($schema);
            $bound  = $bounds[self::objectKey($schema, self::nonEmptyString($table)) . "\0" . self::nonEmptyString($name)] ?? [];

            $columns[] = [
                'schema' => $schema,
                'table' => self::nonEmptyString($table),
                'column' => $this->createColumn(
                    self::nonEmptyString($name),
                    self::nonEmptyString($dataType),
                    (bool) $nullable,
                    is_string($default) ? $default : null,
                    is_string($comment) ? $comment : null,
                    $schema,
                    $bound['min'] ?? null,
                    $bound['max'] ?? null,
                ),
            ];
        }

        return $columns;
    }

    /**
     * Reads the single-column CHECK constraints `(col >= n)` and `(col <= n)`,
     * which DBAL declares for a column's minimum and maximum value. Any other
     * CHECK is ignored.
     *
     * @return array<string, array{min?: int|float, max?: int|float}> By schema, table and column.
     *
     * @throws Exception
     */
    private function fetchBounds(string|null $schemaName, string|null $tableName): array
    {
        $params = [];
        $where  = $this->buildTablePredicate($schemaName, $tableName, $params);

        $sql = <<<SQL
            SELECT c.schema_name,
                   c.table_name,
                   c.expression,
                   c.constraint_column_names
            FROM duckdb_constraints() c
            JOIN duckdb_tables() t ON t.table_oid = c.table_oid
            JOIN duckdb_schemas() s ON s.oid = t.schema_oid
            WHERE {$where}
              AND c.constraint_type = 'CHECK'
            ORDER BY c.schema_name,
                     c.table_name,
                     c.constraint_index
            SQL;

        $bounds = [];

        foreach ($this->connection->executeQuery($sql, $params)->iterateNumeric() as $row) {
            [$schema, $table, $expression, $columns] = $row;

            $columns = self::nameList($columns);

            if (count($columns) !== 1 || ! is_string($expression)) {
                continue;
            }

            if (
                preg_match(
                    '/^\((?:"((?:[^"]|"")+)"|([^\s()"]+)) (>=|<=) (-?\d+(?:\.\d+)?)\)$/',
                    $expression,
                    $matches,
                ) !== 1
            ) {
                continue;
            }

            $column = $matches[1] !== '' ? str_replace('""', '"', $matches[1]) : $matches[2];

            if (strcasecmp($column, $columns[0]) !== 0) {
                continue;
            }

            $literal = $matches[4];
            $value   = str_contains($literal, '.') ? (float) $literal : (int) $literal;
            $key     = self::objectKey(self::nonEmptyString($schema), self::nonEmptyString($table)) . "\0" . $columns[0];

            $bounds[$key][$matches[3] === '>=' ? 'min' : 'max'] = $value;
        }

        return $bounds;
    }

    /**
     * Selects the catalog rows of the PRIMARY KEY, UNIQUE and FOREIGN KEY
     * constraints, in declaration order.
     *
     * @throws Exception
     */
    public function selectConstraints(string|null $schemaName, string|null $tableName): Result
    {
        $params = [];
        $where  = $this->buildTablePredicate($schemaName, $tableName, $params);

        $sql = <<<SQL
            SELECT c.schema_name,
                   c.table_name,
                   c.constraint_index,
                   c.constraint_type,
                   c.constraint_column_names,
                   c.referenced_table,
                   c.referenced_column_names
            FROM duckdb_constraints() c
            JOIN duckdb_tables() t ON t.table_oid = c.table_oid
            JOIN duckdb_schemas() s ON s.oid = t.schema_oid
            WHERE {$where}
              AND c.constraint_type IN ('PRIMARY KEY', 'UNIQUE', 'FOREIGN KEY')
            ORDER BY c.schema_name,
                     c.table_name,
                     c.constraint_index
            SQL;

        return $this->connection->executeQuery($sql, $params);
    }

    /**
     * @return list<array{
     *     schema: non-empty-string,
     *     table: non-empty-string,
     *     columns: non-empty-list<non-empty-string>,
     * }>
     *
     * @throws Exception
     */
    public function fetchPrimaryKeys(string|null $schemaName, string|null $tableName): array
    {
        $primaryKeys = [];

        foreach ($this->fetchConstraints($schemaName, $tableName) as $constraint) {
            if ($constraint['type'] !== 'PRIMARY KEY') {
                continue;
            }

            $primaryKeys[] = [
                'schema' => $constraint['schema'],
                'table' => $constraint['table'],
                'columns' => $constraint['columns'],
            ];
        }

        return $primaryKeys;
    }

    /**
     * @return list<array{
     *     schema: non-empty-string,
     *     table: non-empty-string,
     *     id: int,
     *     name: non-empty-string,
     *     columns: non-empty-list<non-empty-string>,
     *     referencedTable: non-empty-string,
     *     referencedColumns: non-empty-list<non-empty-string>,
     * }>
     *
     * @throws Exception
     */
    public function fetchForeignKeys(string|null $schemaName, string|null $tableName): array
    {
        $currentSchema = $this->fetchCurrentSchemaName();
        $foreignKeys   = [];
        $usedNames     = [];

        foreach ($this->fetchConstraints($schemaName, $tableName) as $constraint) {
            if ($constraint['type'] !== 'FOREIGN KEY' || $constraint['referencedTable'] === null) {
                continue;
            }

            $tableKey = $constraint['schema'] . '.' . $constraint['table'];
            $name     = $this->generateIdentifierName(
                $this->dbalTableName($constraint['schema'], $constraint['table'], $currentSchema),
                $constraint['columns'],
                'fk',
            );

            if (isset($usedNames[$tableKey][strtolower($name)])) {
                $name .= '_' . $constraint['index'];
            }

            $usedNames[$tableKey][strtolower($name)] = true;

            $foreignKeys[] = [
                'schema' => $constraint['schema'],
                'table' => $constraint['table'],
                'id' => $constraint['index'],
                'name' => $name,
                'columns' => $constraint['columns'],
                'referencedTable' => $constraint['referencedTable'],
                'referencedColumns' => $constraint['referencedColumns'],
            ];
        }

        return $foreignKeys;
    }

    /**
     * Selects the catalog rows of the indexes created with CREATE INDEX.
     *
     * @throws Exception
     */
    public function selectIndexes(string|null $schemaName, string|null $tableName): Result
    {
        $params = [];
        $where  = $this->buildTablePredicate($schemaName, $tableName, $params);

        $sql = <<<SQL
            SELECT i.schema_name,
                   i.table_name,
                   i.index_name,
                   i.is_unique,
                   i.expressions
            FROM duckdb_indexes() i
            JOIN duckdb_tables() t ON t.table_oid = i.table_oid
            JOIN duckdb_schemas() s ON s.oid = t.schema_oid
            WHERE {$where}
            ORDER BY i.schema_name,
                     i.table_name,
                     i.index_name
            SQL;

        return $this->connection->executeQuery($sql, $params);
    }

    /**
     * The indexes created with CREATE INDEX, plus one unique index per UNIQUE
     * constraint that no such index covers. Indexes on expressions are
     * skipped, because DBAL cannot represent them.
     *
     * @return list<array{
     *     schema: non-empty-string,
     *     table: non-empty-string,
     *     name: non-empty-string,
     *     unique: bool,
     *     columns: non-empty-list<non-empty-string>,
     * }>
     *
     * @throws Exception
     */
    public function fetchIndexes(string|null $schemaName, string|null $tableName): array
    {
        $indexes          = [];
        $uniqueColumnSets = [];

        foreach ($this->selectIndexes($schemaName, $tableName)->iterateNumeric() as $row) {
            [$schema, $table, $name, $unique, $expressions] = $row;

            $columns = self::parseIndexColumns(is_string($expressions) ? $expressions : '');

            if ($columns === null) {
                continue;
            }

            $index = [
                'schema' => self::nonEmptyString($schema),
                'table' => self::nonEmptyString($table),
                'name' => self::nonEmptyString($name),
                'unique' => (bool) $unique,
                'columns' => $columns,
            ];

            if ($index['unique']) {
                $uniqueColumnSets[self::columnSetKey($index['schema'], $index['table'], $columns)] = true;
            }

            $indexes[] = $index;
        }

        $currentSchema = null;

        foreach ($this->fetchConstraints($schemaName, $tableName) as $constraint) {
            if ($constraint['type'] !== 'UNIQUE') {
                continue;
            }

            $key = self::columnSetKey($constraint['schema'], $constraint['table'], $constraint['columns']);

            if (isset($uniqueColumnSets[$key])) {
                continue;
            }

            $uniqueColumnSets[$key] = true;

            $currentSchema ??= $this->fetchCurrentSchemaName();

            $indexes[] = [
                'schema' => $constraint['schema'],
                'table' => $constraint['table'],
                'name' => $this->generateIdentifierName(
                    $this->dbalTableName($constraint['schema'], $constraint['table'], $currentSchema),
                    $constraint['columns'],
                    'uniq',
                ),
                'unique' => true,
                'columns' => $constraint['columns'],
            ];
        }

        usort($indexes, static function (array $a, array $b): int {
            return [$a['schema'], $a['table'], $a['name']] <=> [$b['schema'], $b['table'], $b['name']];
        });

        return $indexes;
    }

    /**
     * @return list<array{schema: non-empty-string, name: non-empty-string, definition: string}>
     *
     * @throws Exception
     */
    public function fetchViews(): array
    {
        $sql = <<<'SQL'
            SELECT v.schema_name,
                   v.view_name,
                   v.sql
            FROM duckdb_views() v
            JOIN duckdb_schemas() s ON s.oid = v.schema_oid
            WHERE v.database_name = current_database()
              AND NOT v.internal
              AND NOT v.temporary
              AND s.parent_schema IS NULL
            ORDER BY v.schema_name,
                     v.view_name
            SQL;

        $views = [];

        foreach ($this->connection->iterateNumeric($sql) as [$schema, $name, $sql]) {
            $views[] = [
                'schema' => self::nonEmptyString($schema),
                'name' => self::nonEmptyString($name),
                'definition' => self::viewDefinition(is_string($sql) ? $sql : ''),
            ];
        }

        return $views;
    }

    /**
     * The sequences of the current database, except the owned sequences of
     * autoincrement columns.
     *
     * @return list<array{
     *     schema: non-empty-string,
     *     name: non-empty-string,
     *     allocationSize: int,
     *     initialValue: int,
     * }>
     *
     * @throws Exception
     */
    public function fetchSequences(): array
    {
        $owned = $this->fetchOwnedSequenceKeys();

        $sql = <<<'SQL'
            SELECT q.schema_name,
                   q.sequence_name,
                   q.increment_by,
                   q.start_value
            FROM duckdb_sequences() q
            JOIN duckdb_schemas() s ON s.oid = q.schema_oid
            WHERE q.database_name = current_database()
              AND NOT q.temporary
              AND s.parent_schema IS NULL
            ORDER BY q.schema_name,
                     q.sequence_name
            SQL;

        $sequences = [];

        foreach ($this->connection->iterateNumeric($sql) as [$schema, $name, $increment, $start]) {
            $schema = self::nonEmptyString($schema);
            $name   = self::nonEmptyString($name);

            if (isset($owned[self::objectKey($schema, $name)])) {
                continue;
            }

            $sequences[] = [
                'schema' => $schema,
                'name' => $name,
                'allocationSize' => self::integer($increment),
                'initialValue' => self::integer($start),
            ];
        }

        return $sequences;
    }

    /**
     * Builds a column from its catalog description.
     *
     * The DuckDB type string is parsed and mapped through the platform's type
     * mappings; a type without a mapping becomes {@see NativeType}. Columns of
     * the package's own `duckdb_*` types carry the DuckDB type string in the
     * `duckdb_type` platform option; LIST, STRUCT and MAP columns also carry
     * their inner types in the options their types declare them with.
     *
     * A default that draws from an owned sequence makes the column
     * `autoincrement` without a default. The sequence's name, qualified with
     * the table's schema when given, is kept in the platform option
     * {@see DuckDBPlatform::OWNED_SEQUENCE_OPTION}.
     *
     * @param non-empty-string  $name
     * @param non-empty-string  $dataType
     * @param ?non-empty-string $schemaName The schema of the column's table.
     * @param int|float|null    $minimum    The bound of a `col >= n` CHECK, kept as the `min` platform option.
     * @param int|float|null    $maximum    The bound of a `col <= n` CHECK, kept as the `max` platform option.
     */
    public function createColumn(
        string $name,
        string $dataType,
        bool $nullable,
        string|null $default,
        string|null $comment,
        string|null $schemaName = null,
        int|float|null $minimum = null,
        int|float|null $maximum = null,
    ): Column {
        $parsed   = TypeStringParser::parse($dataType);
        $typeName = NativeType::NAME;

        if ($this->platform->hasDoctrineTypeMappingFor($parsed['name'])) {
            $typeName = $this->platform->getDoctrineTypeMapping($parsed['name']);
        }

        $ownedSequence   = $this->ownedSequence($default, $schemaName);
        $platformOptions = [];

        $options = [
            'notnull' => ! $nullable,
            'default' => null,
        ];

        if ($ownedSequence === null) {
            $options['default'] = self::normalizeDefault($default, $typeName);
        } else {
            $options['autoincrement'] = true;

            $platformOptions[DuckDBPlatform::OWNED_SEQUENCE_OPTION] = $ownedSequence->toSQL($this->platform);
        }

        if ($parsed['name'] === 'DECIMAL' && isset($parsed['arguments'][0], $parsed['arguments'][1])) {
            $options['precision'] = $parsed['arguments'][0];
            $options['scale']     = $parsed['arguments'][1];
        }

        if ($comment !== null && $comment !== '') {
            $options['comment'] = $comment;
        }

        if ($minimum !== null) {
            $platformOptions['min'] = $minimum;
        }

        if ($maximum !== null) {
            $platformOptions['max'] = $maximum;
        }

        if (str_starts_with($typeName, 'duckdb_')) {
            $platformOptions[NativeType::DECLARATION_OPTION] = $dataType;
        }

        $platformOptions += match ($typeName) {
            ListType::NAME => ListType::platformOptionsFor($parsed),
            StructType::NAME => StructType::platformOptionsFor($parsed),
            MapType::NAME => MapType::platformOptionsFor($parsed),
            default => [],
        };

        if ($platformOptions !== []) {
            $options['platformOptions'] = $platformOptions;
        }

        return new Column($this->platform->quoteSingleIdentifier($name), $typeName, $options);
    }

    /**
     * Converts a default expression as DuckDB prints it into the value DBAL
     * declares, so the platform renders the same DEFAULT clause again.
     *
     * NULL becomes null, a string literal its unquoted value, the current
     * date and time functions DBAL's default expressions, boolean and numeric
     * literals PHP scalars. Any other expression, including `nextval(...)`,
     * is returned as written.
     */
    public static function normalizeDefault(string|null $expression, string $typeName): mixed
    {
        if ($expression === null) {
            return null;
        }

        $expression = trim($expression);

        if (strcasecmp($expression, 'NULL') === 0) {
            return null;
        }

        if (preg_match("/^'((?:[^']|'')*)'$/s", $expression, $matches) === 1) {
            return str_replace("''", "'", $matches[1]);
        }

        $type     = Type::getType($typeName);
        $function = strtolower($expression);

        if (
            $type instanceof PhpDateTimeMappingType
            && in_array($function, ['current_timestamp', 'current_timestamp()', 'now()', 'get_current_timestamp()'], true)
        ) {
            return new CurrentTimestamp();
        }

        if ($type instanceof PhpDateMappingType && in_array($function, ['current_date', 'current_date()', 'today()'], true)) {
            return new CurrentDate();
        }

        if ($type instanceof PhpTimeMappingType && in_array($function, ['current_time', 'current_time()', 'get_current_time()'], true)) {
            return new CurrentTime();
        }

        if ($type instanceof BooleanType && ($function === 'true' || $function === 'false')) {
            return $function === 'true';
        }

        if (preg_match('/^-?\d+$/', $expression) === 1) {
            $integer = (int) $expression;

            return (string) $integer === $expression ? $integer : $expression;
        }

        if (is_numeric($expression)) {
            return (float) $expression;
        }

        return $expression;
    }

    /**
     * Generates a name the way DBAL's Table names an unnamed index or foreign
     * key, from the table name as DBAL reports it and the column names.
     *
     * @param non-empty-string       $tableName
     * @param list<non-empty-string> $columnNames
     *
     * @return non-empty-string
     */
    public function generateIdentifierName(string $tableName, array $columnNames, string $prefix): string
    {
        $hash = implode('', array_map(
            static fn (string $part): string => dechex(crc32($part)),
            [$tableName, ...$columnNames],
        ));

        return self::nonEmptyString(strtoupper(substr($prefix . '_' . $hash, 0, $this->platform->getMaxIdentifierLength())));
    }

    /**
     * The owned sequences that column defaults of the current database's
     * tables draw from, keyed by {@see self::objectKey()}.
     *
     * @return array<string, true>
     *
     * @throws Exception
     */
    private function fetchOwnedSequenceKeys(): array
    {
        $params = [];
        $where  = $this->buildTablePredicate(null, null, $params);

        $sql = <<<SQL
            SELECT c.schema_name,
                   c.column_default
            FROM duckdb_columns() c
            JOIN duckdb_tables() t ON t.table_oid = c.table_oid
            JOIN duckdb_schemas() s ON s.oid = t.schema_oid
            WHERE {$where}
              AND c.column_default IS NOT NULL
            SQL;

        $keys = [];

        foreach ($this->connection->iterateNumeric($sql, $params) as [$schema, $default]) {
            $sequence = $this->ownedSequence(is_string($default) ? $default : null, self::nonEmptyString($schema));

            if ($sequence === null) {
                continue;
            }

            $qualifier = $sequence->getQualifier();

            if ($qualifier === null) {
                continue;
            }

            $keys[self::objectKey($qualifier->getValue(), $sequence->getUnqualifiedName()->getValue())] = true;
        }

        return $keys;
    }

    /**
     * The owned sequence a column default draws from, qualified with the
     * given schema when the default names none: the platform creates an owned
     * sequence in the schema of its table.
     *
     * @param ?non-empty-string $schemaName
     */
    private function ownedSequence(string|null $default, string|null $schemaName): OptionallyQualifiedName|null
    {
        if ($default === null) {
            return null;
        }

        $sequence = $this->platform->parseOwnedSequenceDefault($default);

        if ($sequence === null || $sequence->getQualifier() !== null || $schemaName === null) {
            return $sequence;
        }

        return new OptionallyQualifiedName($sequence->getUnqualifiedName(), Identifier::quoted($schemaName));
    }

    /** DuckDB matches identifiers case-insensitively, quoted or not. */
    private static function objectKey(string $schemaName, string $name): string
    {
        return strtolower($schemaName) . "\0" . strtolower($name);
    }

    /**
     * The table name as DBAL reports it: unqualified in the current schema,
     * qualified elsewhere.
     *
     * @param non-empty-string $schemaName
     * @param non-empty-string $tableName
     * @param non-empty-string $currentSchemaName
     *
     * @return non-empty-string
     */
    private function dbalTableName(string $schemaName, string $tableName, string $currentSchemaName): string
    {
        if ($schemaName === $currentSchemaName) {
            return $tableName;
        }

        return $schemaName . '.' . $tableName;
    }

    /**
     * @return list<array{
     *     schema: non-empty-string,
     *     table: non-empty-string,
     *     index: int,
     *     type: string,
     *     columns: non-empty-list<non-empty-string>,
     *     referencedTable: non-empty-string|null,
     *     referencedColumns: non-empty-list<non-empty-string>,
     * }>
     *
     * @throws Exception
     */
    private function fetchConstraints(string|null $schemaName, string|null $tableName): array
    {
        $constraints = [];

        foreach ($this->selectConstraints($schemaName, $tableName)->iterateNumeric() as $row) {
            [$schema, $table, $index, $type, $columns, $referencedTable, $referencedColumns] = $row;

            $columns = self::nameList($columns);

            if ($columns === []) {
                continue;
            }

            $referenced = self::nameList($referencedColumns);

            $constraints[] = [
                'schema' => self::nonEmptyString($schema),
                'table' => self::nonEmptyString($table),
                'index' => self::integer($index),
                'type' => is_string($type) ? $type : '',
                'columns' => $columns,
                'referencedTable' => is_string($referencedTable) && $referencedTable !== '' ? $referencedTable : null,
                'referencedColumns' => $referenced === [] ? $columns : $referenced,
            ];
        }

        return $constraints;
    }

    /**
     * Restricts a catalog query to base tables of the current database,
     * optionally to one table. Without a schema name, the current schema is
     * used.
     *
     * @param list<string> $params
     */
    private function buildTablePredicate(string|null $schemaName, string|null $tableName, array &$params): string
    {
        $conditions = [
            't.database_name = current_database()',
            'NOT t.internal',
            'NOT t.temporary',
            's.parent_schema IS NULL',
        ];

        if ($tableName !== null) {
            if ($schemaName === null) {
                $conditions[] = 'lower(t.schema_name) = lower(current_schema())';
            } else {
                $conditions[] = 'lower(t.schema_name) = lower(?)';
                $params[]     = $schemaName;
            }

            $conditions[] = 'lower(t.table_name) = lower(?)';
            $params[]     = $tableName;
        }

        return implode(' AND ', $conditions);
    }

    /**
     * Parses the `expressions` column of duckdb_indexes(), such as `[a, b]` or
     * `['"Odd Name"', b]`, into column names. Returns null when an element is
     * an expression rather than a column.
     *
     * @return non-empty-list<non-empty-string>|null
     */
    private static function parseIndexColumns(string $expressions): array|null
    {
        $expressions = trim($expressions);

        if (strlen($expressions) < 3 || $expressions[0] !== '[' || $expressions[strlen($expressions) - 1] !== ']') {
            return null;
        }

        $columns = [];

        foreach (self::splitList(substr($expressions, 1, -1)) as $element) {
            if (preg_match("/^'((?:[^']|'')*)'$/s", $element, $matches) === 1) {
                $element = str_replace("''", "'", $matches[1]);
            }

            if (preg_match('/^"((?:[^"]|"")+)"$/s', $element, $matches) === 1) {
                $columns[] = self::nonEmptyString(str_replace('""', '"', $matches[1]));

                continue;
            }

            if (preg_match('/^[A-Za-z_][A-Za-z0-9_$]*$/', $element) === 1) {
                $columns[] = self::nonEmptyString($element);

                continue;
            }

            return null;
        }

        if ($columns === []) {
            return null;
        }

        return $columns;
    }

    /**
     * Splits a comma-separated list at the top level, keeping quoted text
     * and parenthesized expressions together.
     *
     * @return list<string>
     */
    private static function splitList(string $list): array
    {
        $parts  = [];
        $depth  = 0;
        $quote  = null;
        $start  = 0;
        $length = strlen($list);

        for ($position = 0; $position < $length; $position++) {
            $character = $list[$position];

            if ($quote !== null) {
                if ($character === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($character === "'" || $character === '"') {
                $quote = $character;
            } elseif ($character === '(' || $character === '[') {
                $depth++;
            } elseif ($character === ')' || $character === ']') {
                $depth--;
            } elseif ($character === ',' && $depth === 0) {
                $parts[] = trim(substr($list, $start, $position - $start));
                $start   = $position + 1;
            }
        }

        $parts[] = trim(substr($list, $start));

        return $parts;
    }

    /** The SELECT of a view, from the CREATE VIEW statement DuckDB keeps. */
    private static function viewDefinition(string $sql): string
    {
        $sql = trim($sql);

        if (preg_match('/^CREATE\s+(?:OR\s+REPLACE\s+)?VIEW\s+.+?\s+AS\s+(.*?);?$/is', $sql, $matches) === 1) {
            return $matches[1];
        }

        return $sql;
    }

    /** @param non-empty-list<non-empty-string> $columns */
    private static function columnSetKey(string $schemaName, string $tableName, array $columns): string
    {
        $names = array_map(strtolower(...), $columns);

        sort($names);

        return strtolower($schemaName . "\0" . $tableName) . "\0" . implode("\0", $names);
    }

    /** @return list<non-empty-string> */
    private static function nameList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $names = [];

        foreach ($value as $name) {
            if (! is_string($name) || $name === '') {
                return [];
            }

            $names[] = $name;
        }

        return $names;
    }

    /** @return non-empty-string */
    private static function nonEmptyString(mixed $value): string
    {
        if (! is_string($value) || $value === '') {
            throw new UnexpectedValueException(sprintf(
                'Expected a non-empty string from the DuckDB catalog, got %s.',
                get_debug_type($value),
            ));
        }

        return $value;
    }

    private static function integer(mixed $value): int
    {
        if (! is_numeric($value)) {
            return 0;
        }

        return (int) $value;
    }
}
