<?php

declare(strict_types=1);

namespace MartinJuul\DBAL\DuckDB\Schema;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Exception\InvalidArgumentException;
use Doctrine\DBAL\Result;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\ComparatorConfig;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Doctrine\DBAL\Schema\Name\Parsers;
use Doctrine\DBAL\Schema\SchemaDiff;
use Doctrine\DBAL\Schema\Sequence;
use Doctrine\DBAL\Schema\TableDiff;
use Doctrine\DBAL\Schema\View;
use DuckDB\Database;
use DuckDB\Exception as DuckDBException;
use MartinJuul\DBAL\DuckDB\Driver\Exception\DriverError;
use MartinJuul\DBAL\DuckDB\Platform\DuckDBPlatform;

use function array_filter;
use function array_keys;
use function array_map;
use function array_pop;
use function array_values;
use function assert;
use function count;
use function explode;
use function fclose;
use function file_exists;
use function fopen;
use function fread;
use function func_get_arg;
use function func_num_args;
use function in_array;
use function is_string;
use function preg_match;
use function realpath;
use function sprintf;
use function str_contains;
use function str_ends_with;
use function str_replace;
use function str_starts_with;
use function strlen;
use function substr;
use function unlink;

/**
 * Schema manager for DuckDB 2.0.
 *
 * Both of DBAL 4.5's introspection paths read DuckDB's catalog through
 * {@see DuckDBMetadataProvider}: the legacy list and introspect methods, which
 * ORM's schema tool and Migrations use, and the metadata-provider-based
 * introspect methods.
 *
 * A DuckDB database is a file, so the database methods work on file paths:
 * {@see createDatabase()} creates an empty database file and
 * {@see dropDatabase()} deletes one with its write-ahead log. Both work from
 * any connection, including an in-memory one.
 *
 * @extends AbstractSchemaManager<DuckDBPlatform>
 */
class DuckDBSchemaManager extends AbstractSchemaManager
{
    /** The bytes DuckDB writes at offset 8 of every database file. */
    private const string FILE_MAGIC = 'DUCK';

    private readonly DuckDBMetadataProvider $metadataProvider;

    public function __construct(Connection $connection, DuckDBPlatform $platform)
    {
        parent::__construct($connection, $platform);

        $this->metadataProvider = $platform->createMetadataProvider($connection);
    }

    /**
     * The paths of the file databases attached to the connection. An
     * in-memory connection has none.
     *
     * @return list<non-empty-string>
     */
    public function listDatabases(): array
    {
        return $this->metadataProvider->fetchDatabasePaths();
    }

    /**
     * Creates an empty database file at the given path. The name may be
     * quoted as an identifier, which DoctrineBundle does. An existing DuckDB
     * file is opened and left unchanged.
     *
     * @throws Exception
     */
    public function createDatabase(string $database): void
    {
        $path = self::databasePath($database, 'create');

        try {
            $handle = new Database($path);
        } catch (DuckDBException $e) {
            throw $this->connection->getDriver()->getExceptionConverter()->convert(DriverError::fromDuckDB($e), null);
        }

        unset($handle);
    }

    /**
     * Deletes the database file at the given path and its write-ahead log.
     * The name may be quoted as an identifier, which DoctrineBundle does. A
     * missing file is not an error; a file that is not a DuckDB database is
     * refused. When the connection has the file open, it is closed first.
     *
     * @throws Exception
     */
    public function dropDatabase(string $database): void
    {
        $path = self::databasePath($database, 'drop');

        if ($this->connection->isConnected() && $this->isAttached($path)) {
            $this->connection->close();
        }

        if (file_exists($path)) {
            if (! self::isDuckDBFile($path)) {
                throw new InvalidArgumentException(sprintf(
                    'Cannot drop the DuckDB database "%s": the file is not a DuckDB database, so it is left in place.',
                    $path,
                ));
            }

            self::deleteFile($path);
        }

        if (! file_exists($path . '.wal')) {
            return;
        }

        self::deleteFile($path . '.wal');
    }

    /**
     * Drops the table, then the owned sequences of its autoincrement columns,
     * which DuckDB refuses to drop while the table exists. The sequences are
     * found from the column defaults, so a renamed table drops the sequence
     * that still carries its old name.
     *
     * @throws Exception
     */
    public function dropTable(string $name): void
    {
        $sequences = [];

        foreach ($this->fetchTableColumns('', $this->normalizeName($name)) as $row) {
            $column = $row['column'];
            assert($column instanceof Column);

            $sequence = DuckDBPlatform::getIntrospectedOwnedSequenceName($column->toArray(true));

            if ($sequence === null) {
                continue;
            }

            $sequences[] = $sequence->toSQL($this->platform);
        }

        $this->connection->executeStatement($this->platform->getDropTableSQL($name));

        foreach ($sequences as $sequence) {
            $this->connection->executeStatement($this->platform->getDropSequenceSQL($sequence));
        }
    }

    /**
     * A comparator that also decides, per altered table, between native ALTER
     * statements and a rebuild; see {@see DuckDBComparator}.
     */
    public function createComparator(/* ComparatorConfig $config = new ComparatorConfig() */): DuckDBComparator
    {
        $config = func_num_args() > 0 ? func_get_arg(0) : new ComparatorConfig();
        assert($config instanceof ComparatorConfig);

        return new DuckDBComparator($this->platform, $config, $this->connection);
    }

    /**
     * Applies the schema diff, running the rebuild of a self-referencing
     * table one tree level at a time, which generated SQL cannot express.
     *
     * @throws Exception
     */
    public function alterSchema(SchemaDiff $schemaDiff): void
    {
        $rebuilder = new TableRebuilder($this->platform);

        $rebuilder->run($this->connection, $rebuilder->schemaDiffSteps($schemaDiff));
    }

    /**
     * Applies the table diff; a rebuild covers only this table.
     *
     * @throws Exception
     */
    public function alterTable(TableDiff $tableDiff): void
    {
        $rebuilder = new TableRebuilder($this->platform);

        $rebuilder->run($this->connection, $rebuilder->tableDiffSteps($tableDiff));
    }

    /**
     * Renames the table natively when nothing stops DuckDB from doing so: no
     * index on it and no foreign key referencing it, its own included.
     * Otherwise rebuilds it under the new name together with every table that
     * references it, transitively, retargeting their foreign keys. Either way
     * the owned sequences keep their names, because DuckDB cannot rename
     * sequences.
     *
     * @throws Exception
     */
    public function renameTable(string $name, string $newName): void
    {
        $tables = [];

        foreach ($this->listTables() as $table) {
            $tables[RebuildPlan::tableKey($table->getObjectName())] = $table;
        }

        $oldName = Parsers::parseOptionallyQualifiedName($name);
        $key     = RebuildPlan::tableKey($oldName);
        $table   = $tables[$key] ?? $this->introspectTable($name);

        $referencedBy = [];

        foreach ($tables as $childKey => $child) {
            foreach ($child->getForeignKeys() as $foreignKey) {
                $referencedBy[RebuildPlan::tableKey($foreignKey->getReferencedTableName())][$childKey] = true;
            }
        }

        $indexed = false;

        foreach ($table->getIndexes() as $index) {
            if ($index->isPrimary()) {
                continue;
            }

            $indexed = true;
        }

        if (! $indexed && ! isset($referencedBy[$key])) {
            $this->connection->executeStatement($this->platform->getRenameTableSQL($name, $newName));

            return;
        }

        $renamed = Parsers::parseOptionallyQualifiedName($newName);
        $closure = [$key => $table];
        $queue   = [$key];

        while (count($queue) > 0) {
            $parent = array_pop($queue);

            foreach (array_keys($referencedBy[$parent] ?? []) as $childKey) {
                if (isset($closure[$childKey])) {
                    continue;
                }

                $closure[$childKey] = $tables[$childKey];
                $queue[]            = $childKey;
            }
        }

        DuckDBComparator::assertNoGeneratedColumns($this->connection, $closure);

        $rebuilds = [];

        foreach ($closure as $closureKey => $old) {
            $new = $old->edit()->setForeignKeyConstraints(...array_map(
                static fn (ForeignKeyConstraint $foreignKey): ForeignKeyConstraint => RebuildPlan::tableKey(
                    $foreignKey->getReferencedTableName(),
                ) === $key
                    ? $foreignKey->edit()->setReferencedTableName($renamed)->create()
                    : $foreignKey,
                array_values($old->getForeignKeys()),
            ));

            if ($closureKey === $key) {
                $new->setName($renamed);
            }

            $rebuilds[$closureKey] = RebuildPlan::rebuild($old, $new->create(), null);
        }

        $rebuilder = new TableRebuilder($this->platform);

        $rebuilder->run($this->connection, $rebuilder->rebuildSteps($rebuilds));
    }

    /**
     * The schemas of the current database, including the default `main`
     * schema, which Doctrine Migrations adds to the schema it compares with.
     *
     * {@inheritDoc}
     */
    public function listSchemaNames(): array
    {
        return $this->metadataProvider->fetchSchemaNames();
    }

    /** {@inheritDoc} */
    public function listSequences(): array
    {
        $currentSchema = $this->getCurrentSchemaName();
        $sequences     = [];

        foreach ($this->metadataProvider->fetchSequences() as $sequence) {
            $sequences[] = new Sequence(
                $this->legacyName($sequence['schema'], $sequence['name'], $currentSchema),
                $sequence['allocationSize'],
                $sequence['initialValue'],
            );
        }

        $filter = $this->connection->getConfiguration()->getSchemaAssetsFilter();

        return array_values(array_filter($sequences, $filter));
    }

    /** {@inheritDoc} */
    public function listViews(): array
    {
        $currentSchema = $this->getCurrentSchemaName();

        return array_map(
            fn (array $view): View => new View(
                $this->legacyName($view['schema'], $view['name'], $currentSchema),
                $view['definition'],
            ),
            $this->metadataProvider->fetchViews(),
        );
    }

    /** @return non-empty-string */
    protected function determineCurrentSchemaName(): string
    {
        return $this->metadataProvider->fetchCurrentSchemaName();
    }

    protected function selectTableNames(string $databaseName): Result
    {
        return $this->metadataProvider->selectTables(null, null);
    }

    protected function selectTableColumns(string $databaseName, string|null $tableName = null): Result
    {
        [$schema, $table] = $this->splitTableName($tableName);

        return $this->metadataProvider->selectColumns($schema, $table);
    }

    protected function selectIndexColumns(string $databaseName, string|null $tableName = null): Result
    {
        [$schema, $table] = $this->splitTableName($tableName);

        return $this->metadataProvider->selectIndexes($schema, $table);
    }

    protected function selectForeignKeyColumns(string $databaseName, string|null $tableName = null): Result
    {
        [$schema, $table] = $this->splitTableName($tableName);

        return $this->metadataProvider->selectConstraints($schema, $table);
    }

    /** {@inheritDoc} */
    protected function fetchTableColumns(string $databaseName, string|null $tableName = null): array
    {
        [$schema, $table] = $this->splitTableName($tableName);

        $rows = [];

        foreach ($this->metadataProvider->fetchColumns($schema, $table) as $column) {
            $rows[] = [
                'schema_name' => $column['schema'],
                'table_name' => $column['table'],
                'column' => $column['column'],
            ];
        }

        return $rows;
    }

    /**
     * The primary key under DBAL's `primary` name, then the indexes.
     *
     * {@inheritDoc}
     */
    protected function fetchIndexColumns(string $databaseName, string|null $tableName = null): array
    {
        [$schema, $table] = $this->splitTableName($tableName);

        $rows = [];

        foreach ($this->metadataProvider->fetchPrimaryKeys($schema, $table) as $primaryKey) {
            foreach ($primaryKey['columns'] as $column) {
                $rows[] = [
                    'schema_name' => $primaryKey['schema'],
                    'table_name' => $primaryKey['table'],
                    'key_name' => 'primary',
                    'primary' => true,
                    'non_unique' => false,
                    'column_name' => $this->quoteIfNeeded($column),
                ];
            }
        }

        foreach ($this->metadataProvider->fetchIndexes($schema, $table) as $index) {
            foreach ($index['columns'] as $column) {
                $rows[] = [
                    'schema_name' => $index['schema'],
                    'table_name' => $index['table'],
                    'key_name' => $this->quoteIfNeeded($index['name']),
                    'primary' => false,
                    'non_unique' => ! $index['unique'],
                    'column_name' => $this->quoteIfNeeded($column),
                ];
            }
        }

        return $rows;
    }

    /** {@inheritDoc} */
    protected function fetchForeignKeyColumns(string $databaseName, string|null $tableName = null): array
    {
        [$schema, $table] = $this->splitTableName($tableName);

        $currentSchema = $this->getCurrentSchemaName();
        $rows          = [];

        foreach ($this->metadataProvider->fetchForeignKeys($schema, $table) as $foreignKey) {
            $rows[] = [
                'schema_name' => $foreignKey['schema'],
                'table_name' => $foreignKey['table'],
                'constraint' => new ForeignKeyConstraint(
                    array_map($this->quoteIfNeeded(...), $foreignKey['columns']),
                    $this->legacyName($foreignKey['schema'], $foreignKey['referencedTable'], $currentSchema),
                    array_map($this->quoteIfNeeded(...), $foreignKey['referencedColumns']),
                    $this->quoteIfNeeded($foreignKey['name']),
                ),
            ];
        }

        return $rows;
    }

    /** {@inheritDoc} */
    protected function fetchTableOptionsByTable(string $databaseName, string|null $tableName = null): array
    {
        [$schema, $table] = $this->splitTableName($tableName);

        $options = [];

        foreach ($this->metadataProvider->fetchTables($schema, $table) as $row) {
            // A single table is looked up under the name the caller passed.
            $name = $tableName ?? $this->_getPortableTableDefinition([
                'schema_name' => $row['schema'],
                'table_name' => $row['table'],
            ]);

            $options[$name] = $row['options'];
        }

        return $options;
    }

    /** {@inheritDoc} */
    // phpcs:ignore PSR2.Methods.MethodDeclaration.Underscore -- name defined by AbstractSchemaManager
    protected function _getPortableTableColumnDefinition(array $tableColumn): Column
    {
        $column = $tableColumn['column'];
        assert($column instanceof Column);

        return $column;
    }

    /**
     * @deprecated Use the schema name and the unqualified table name separately instead.
     *
     * {@inheritDoc}
     */
    // phpcs:ignore PSR2.Methods.MethodDeclaration.Underscore -- name defined by AbstractSchemaManager
    protected function _getPortableTableDefinition(array $table): string
    {
        $schema = $table['schema_name'];
        $name   = $table['table_name'];
        assert(is_string($schema) && $schema !== '' && is_string($name) && $name !== '');

        return $this->legacyName($schema, $name, $this->getCurrentSchemaName());
    }

    /** {@inheritDoc} */
    // phpcs:ignore PSR2.Methods.MethodDeclaration.Underscore -- name defined by AbstractSchemaManager
    protected function _getPortableViewDefinition(array $view): View
    {
        $name       = $view['name'];
        $definition = $view['definition'];
        assert(is_string($name) && is_string($definition));

        return new View($name, $definition);
    }

    /** {@inheritDoc} */
    // phpcs:ignore PSR2.Methods.MethodDeclaration.Underscore -- name defined by AbstractSchemaManager
    protected function _getPortableTableForeignKeyDefinition(array $tableForeignKey): ForeignKeyConstraint
    {
        $foreignKey = $tableForeignKey['constraint'];
        assert($foreignKey instanceof ForeignKeyConstraint);

        return $foreignKey;
    }

    /**
     * Splits a table name that the legacy methods pass, with the schema
     * optional, into its parts. Without a schema, the current schema is used.
     *
     * @return array{?string, ?string}
     */
    private function splitTableName(string|null $tableName): array
    {
        if ($tableName === null) {
            return [null, null];
        }

        if (str_contains($tableName, '.')) {
            [$schema, $table] = explode('.', $tableName, 2);

            return [$schema, $table];
        }

        return [$this->getCurrentSchemaName(), $tableName];
    }

    /**
     * A name as the legacy methods report it: unqualified in the current
     * schema, quoted only when needed, so that schema asset filters see plain
     * names.
     *
     * @param non-empty-string $schemaName
     * @param non-empty-string $name
     *
     * @return non-empty-string
     */
    private function legacyName(string $schemaName, string $name, string|null $currentSchemaName): string
    {
        if ($schemaName === $currentSchemaName) {
            return $this->quoteIfNeeded($name);
        }

        return $this->quoteIfNeeded($schemaName) . '.' . $this->quoteIfNeeded($name);
    }

    /**
     * @param non-empty-string $name
     *
     * @return non-empty-string
     */
    private function quoteIfNeeded(string $name): string
    {
        if (
            preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) === 1
            && ! $this->platform->getReservedKeywordsList()->isKeyword($name)
        ) {
            return $name;
        }

        return '"' . str_replace('"', '""', $name) . '"';
    }

    /** @throws Exception */
    private function isAttached(string $path): bool
    {
        $resolved = realpath($path);

        if ($resolved === false) {
            return false;
        }

        return in_array($resolved, $this->metadataProvider->fetchDatabasePaths(), true);
    }

    /**
     * Unquotes a database name quoted as an identifier and refuses names that
     * do not denote a file.
     *
     * @return non-empty-string
     *
     * @throws InvalidArgumentException
     */
    private static function databasePath(string $database, string $operation): string
    {
        $path = $database;

        if (strlen($path) >= 2 && str_starts_with($path, '"') && str_ends_with($path, '"')) {
            $path = str_replace('""', '"', substr($path, 1, -1));
        }

        if ($path === '') {
            throw new InvalidArgumentException(sprintf(
                'Cannot %s a DuckDB database without a file path. Configure the connection with a file path in '
                    . '"dbname" or "path".',
                $operation,
            ));
        }

        if (str_starts_with($path, ':memory:')) {
            throw new InvalidArgumentException(sprintf(
                'Cannot %s the DuckDB database "%s": an in-memory database has no file. Configure the connection '
                    . 'with a file path in "dbname" or "path".',
                $operation,
                $path,
            ));
        }

        return $path;
    }

    private static function isDuckDBFile(string $path): bool
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return false;
        }

        $header = fread($handle, 12);

        fclose($handle);

        return is_string($header) && strlen($header) === 12 && substr($header, 8, 4) === self::FILE_MAGIC;
    }

    /** @throws InvalidArgumentException */
    private static function deleteFile(string $path): void
    {
        if (@unlink($path)) {
            return;
        }

        throw new InvalidArgumentException(sprintf('Cannot drop the DuckDB database: deleting "%s" failed.', $path));
    }
}
