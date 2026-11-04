<?php

declare(strict_types=1);

namespace MartinJuul\DBAL\DuckDB\Platform;

use Closure;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\DateIntervalUnit;
use Doctrine\DBAL\Platforms\Exception\NotSupported;
use Doctrine\DBAL\Platforms\Keywords\KeywordList;
use Doctrine\DBAL\Query\SelectQuery;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\DefaultExpression;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Doctrine\DBAL\Schema\ForeignKeyConstraint\ReferentialAction;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\Index\IndexedColumn;
use Doctrine\DBAL\Schema\Index\IndexType;
use Doctrine\DBAL\Schema\Name\Identifier;
use Doctrine\DBAL\Schema\Name\OptionallyQualifiedName;
use Doctrine\DBAL\Schema\Name\UnqualifiedName;
use Doctrine\DBAL\Schema\Name\UnquotedIdentifierFolding;
use Doctrine\DBAL\Schema\Sequence;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Schema\TableDiff;
use Doctrine\DBAL\Schema\UniqueConstraint;
use Doctrine\DBAL\SQL\Builder\DefaultSelectSQLBuilder;
use Doctrine\DBAL\SQL\Builder\SelectSQLBuilder;
use Doctrine\DBAL\TransactionIsolationLevel;
use Doctrine\DBAL\Types\Types;
use MartinJuul\DBAL\DuckDB\Exception\ForeignKeyActionNotSupported;
use MartinJuul\DBAL\DuckDB\Exception\ForeignKeyCycle;
use MartinJuul\DBAL\DuckDB\Schema\DuckDBMetadataProvider;
use MartinJuul\DBAL\DuckDB\Schema\DuckDBSchemaManager;
use MartinJuul\DBAL\DuckDB\Types\NativeType;

use function array_keys;
use function array_map;
use function array_merge;
use function array_reverse;
use function array_slice;
use function array_unique;
use function array_values;
use function count;
use function crc32;
use function dechex;
use function explode;
use function implode;
use function is_string;
use function preg_match;
use function sort;
use function sprintf;
use function str_contains;
use function str_ends_with;
use function str_replace;
use function strtolower;
use function strtoupper;
use function substr;

/**
 * Doctrine DBAL platform for DuckDB 2.0.
 *
 * Extends AbstractPlatform directly: Doctrine's tests and ORM branch on
 * instanceof PostgreSQLPlatform/SQLitePlatform, and extending either would
 * pull in that engine's assumptions.
 *
 * DuckDB 2.0 creates a foreign key only together with its table, so table
 * creation inlines foreign keys and orders tables parents first, and foreign
 * keys cannot be added or dropped later.
 *
 * DuckDB 2.0 has no identity columns, so an `autoincrement` column draws its
 * values from a sequence that the platform creates with the table: an owned
 * sequence, named after the table and column with the
 * {@see self::OWNED_SEQUENCE_SUFFIX} suffix. Owned sequences are recognised by
 * the column's `nextval` default, never by the table's name, because DuckDB
 * cannot rename sequences and a renamed table keeps its sequence's name.
 *
 * @phpstan-import-type ColumnProperties from Column
 * @phpstan-import-type CreateTableParameters from AbstractPlatform
 */
class DuckDBPlatform extends AbstractPlatform
{
    /**
     * The suffix of the sequences that the platform creates for autoincrement
     * columns. It differs from ORM's `<table>_<column>_seq`, so an ORM
     * sequence never collides with an owned one.
     */
    public const string OWNED_SEQUENCE_SUFFIX = '__autoinc';

    /**
     * The platform option of an introspected autoincrement column that holds
     * the SQL name of the owned sequence its default draws from.
     */
    public const string OWNED_SEQUENCE_OPTION = 'duckdb_owned_sequence';

    private bool $omitForeignKeys = false;

    /**
     * While a table's CREATE statement is built: the column lists that foreign
     * keys in the same batch reference on it, keyed by their column set.
     *
     * @var array<string, non-empty-list<UnqualifiedName>>
     */
    private array $referencedColumnLists = [];

    /**
     * While a table's CREATE statement is built: the `nextval` default of each
     * autoincrement column, keyed by the column's position.
     *
     * @var array<int, string>
     */
    private array $ownedSequenceDefaults = [];

    public function __construct()
    {
        parent::__construct(UnquotedIdentifierFolding::NONE);
    }

    /**
     * Returns a copy of this platform whose generated DDL contains no
     * foreign-key constraints. Referential actions and cycles are then
     * accepted, because no foreign key is created.
     */
    public function withoutForeignKeys(): static
    {
        $platform = clone $this;

        $platform->omitForeignKeys = true;

        return $platform;
    }

    public function omitsForeignKeys(): bool
    {
        return $this->omitForeignKeys;
    }

    /** @param array<string, mixed> $column */
    public function getBooleanTypeDeclarationSQL(array $column): string
    {
        return 'BOOLEAN';
    }

    /** @param array<string, mixed> $column */
    public function getIntegerTypeDeclarationSQL(array $column): string
    {
        return 'INTEGER' . $this->_getCommonIntegerTypeDeclarationSQL($column);
    }

    /** @param array<string, mixed> $column */
    public function getBigIntTypeDeclarationSQL(array $column): string
    {
        return 'BIGINT' . $this->_getCommonIntegerTypeDeclarationSQL($column);
    }

    /** @param array<string, mixed> $column */
    public function getSmallIntTypeDeclarationSQL(array $column): string
    {
        return 'SMALLINT' . $this->_getCommonIntegerTypeDeclarationSQL($column);
    }

    /** @param array<string, mixed> $column */
    // phpcs:ignore PSR2.Methods.MethodDeclaration.Underscore -- name defined by AbstractPlatform
    protected function _getCommonIntegerTypeDeclarationSQL(array $column): string
    {
        return '';
    }

    /** @param array<string, mixed> $column */
    public function getClobTypeDeclarationSQL(array $column): string
    {
        return 'VARCHAR';
    }

    /** @param array<string, mixed> $column */
    public function getBlobTypeDeclarationSQL(array $column): string
    {
        return 'BLOB';
    }

    /** @param array<string, mixed> $column */
    public function getGuidTypeDeclarationSQL(array $column): string
    {
        return 'UUID';
    }

    /** @param array<string, mixed> $column */
    public function getJsonTypeDeclarationSQL(array $column): string
    {
        return 'JSON';
    }

    /** @param array<string, mixed> $column */
    public function getFloatDeclarationSQL(array $column): string
    {
        return 'DOUBLE';
    }

    /** @param array<string, mixed> $column */
    public function getSmallFloatDeclarationSQL(array $column): string
    {
        return 'FLOAT';
    }

    /** @param array<string, mixed> $column */
    public function getDateTimeTypeDeclarationSQL(array $column): string
    {
        return 'TIMESTAMP';
    }

    /** @param array<string, mixed> $column */
    public function getDateTimeTzTypeDeclarationSQL(array $column): string
    {
        return 'TIMESTAMPTZ';
    }

    /** @param array<string, mixed> $column */
    public function getDateTypeDeclarationSQL(array $column): string
    {
        return 'DATE';
    }

    /** @param array<string, mixed> $column */
    public function getTimeTypeDeclarationSQL(array $column): string
    {
        return 'TIME';
    }

    public function getDateTimeTzFormatString(): string
    {
        return 'Y-m-d H:i:sO';
    }

    public function getLocateExpression(string $string, string $substring, string|null $start = null): string
    {
        if ($start === null) {
            return 'STRPOS(' . $string . ', ' . $substring . ')';
        }

        $position = 'STRPOS(SUBSTRING(' . $string . ', ' . $start . '), ' . $substring . ')';

        return 'CASE WHEN ' . $position . ' = 0 THEN 0 ELSE ' . $position . ' + ' . $start . ' - 1 END';
    }

    public function getDateDiffExpression(string $date1, string $date2): string
    {
        return 'DATE_DIFF(\'day\', CAST(' . $date2 . ' AS DATE), CAST(' . $date1 . ' AS DATE))';
    }

    protected function getDateArithmeticIntervalExpression(
        string $date,
        string $operator,
        string $interval,
        DateIntervalUnit $unit,
    ): string {
        $function = match ($unit) {
            DateIntervalUnit::SECOND => 'TO_SECONDS',
            DateIntervalUnit::MINUTE => 'TO_MINUTES',
            DateIntervalUnit::HOUR => 'TO_HOURS',
            DateIntervalUnit::DAY => 'TO_DAYS',
            DateIntervalUnit::WEEK => 'TO_WEEKS',
            DateIntervalUnit::MONTH, DateIntervalUnit::QUARTER => 'TO_MONTHS',
            DateIntervalUnit::YEAR => 'TO_YEARS',
        };

        if ($unit === DateIntervalUnit::QUARTER) {
            $interval = $this->multiplyInterval($interval, 3);
        }

        return '(' . $date . ' ' . $operator . ' ' . $function . '(CAST(' . $interval . ' AS INTEGER)))';
    }

    public function getCurrentDatabaseExpression(): string
    {
        return 'CURRENT_DATABASE()';
    }

    /**
     * Rejects pessimistic locks: DuckDB 2.0 has no row locks, and ignoring the
     * lock would hide lost updates. ORM calls this on every select it builds.
     */
    public function appendLockHint(string $fromClause, LockMode $lockMode): string
    {
        return match ($lockMode) {
            LockMode::NONE, LockMode::OPTIMISTIC => $fromClause,
            LockMode::PESSIMISTIC_READ => throw self::rowLocksNotSupported('LockMode::PESSIMISTIC_READ'),
            LockMode::PESSIMISTIC_WRITE => throw self::rowLocksNotSupported('LockMode::PESSIMISTIC_WRITE'),
        };
    }

    /** Builds selects without FOR UPDATE, rejecting a query that requests it. */
    public function createSelectSQLBuilder(): SelectSQLBuilder
    {
        $reject = self::rowLocksNotSupported(...);

        return new class (new DefaultSelectSQLBuilder($this, null, null), $reject) implements SelectSQLBuilder {
            /** @param Closure(string): NotSupported $reject */
            public function __construct(
                private readonly SelectSQLBuilder $builder,
                private readonly Closure $reject,
            ) {
            }

            public function buildSQL(SelectQuery $query): string
            {
                if ($query->getForUpdate() !== null) {
                    throw ($this->reject)('FOR UPDATE');
                }

                return $this->builder->buildSQL($query);
            }
        };
    }

    /** @return list<string> */
    public function getCreateTableSQL(Table $table): array
    {
        return $this->getCreateTablesSQL([$table]);
    }

    /**
     * Creates the tables parents first, with their foreign keys inline.
     *
     * Unsupported referential actions and foreign-key cycles fail before any
     * statement is produced.
     *
     * @param array<Table> $tables
     *
     * @return list<string>
     */
    public function getCreateTablesSQL(array $tables): array
    {
        $tables     = array_values($tables);
        $referenced = [];

        if (! $this->omitForeignKeys) {
            foreach ($tables as $table) {
                self::assertReferentialActionsSupported($table);
            }

            $tables     = self::sortParentsFirst($tables);
            $referenced = self::collectReferencedColumnLists($tables);
        }

        $sql = [];

        foreach ($tables as $table) {
            $this->referencedColumnLists = $referenced[self::tableKey($table->getObjectName())] ?? [];
            $this->ownedSequenceDefaults = [];

            foreach ($table->getColumns() as $position => $column) {
                if (! $column->getAutoincrement()) {
                    continue;
                }

                $sequence = $this->getOwnedSequenceName($table->getObjectName(), $column)->toSQL($this);

                $sql[] = 'CREATE SEQUENCE ' . $sequence;

                $this->ownedSequenceDefaults[$position] = 'nextval(' . $this->quoteStringLiteral($sequence) . ')';
            }

            try {
                $sql = array_merge($sql, parent::getCreateTableSQL($table));
            } finally {
                $this->referencedColumnLists = [];
                $this->ownedSequenceDefaults = [];
            }
        }

        return $sql;
    }

    /**
     * Drops the tables children first, each followed by the owned sequences
     * of its autoincrement columns, which DuckDB refuses to drop while the
     * table exists. DuckDB cannot drop a foreign key on its own, so no
     * foreign-key drop statements are produced.
     *
     * @param array<Table> $tables
     *
     * @return list<string>
     */
    public function getDropTablesSQL(array $tables): array
    {
        $tables = array_values($tables);

        if (! $this->omitForeignKeys) {
            $tables = array_reverse(self::sortParentsFirst($tables));
        }

        $sql = [];

        foreach ($tables as $table) {
            $sql[] = $this->getDropTableSQL($table->getObjectName()->toSQL($this));

            foreach ($table->getColumns() as $column) {
                if (! $column->getAutoincrement()) {
                    continue;
                }

                // A table that was not created by this platform may lack the sequence.
                $sql[] = 'DROP SEQUENCE IF EXISTS ' . $this->getOwnedSequenceName($table->getObjectName(), $column)
                    ->toSQL($this);
            }
        }

        return $sql;
    }

    /**
     * The owned sequence of an autoincrement column: the sequence named in
     * the column's {@see self::OWNED_SEQUENCE_OPTION} platform option, which
     * introspection sets, or else `<table>_<column>` with the owned suffix,
     * in the table's schema.
     */
    public function getOwnedSequenceName(OptionallyQualifiedName $table, Column $column): OptionallyQualifiedName
    {
        $name = self::getIntrospectedOwnedSequenceName($column->toArray(true));

        if ($name !== null) {
            return $name;
        }

        $tableName  = $table->getUnqualifiedName();
        $columnName = $column->getObjectName()->getIdentifier();
        $value      = $tableName->getValue() . '_' . $columnName->getValue() . self::OWNED_SEQUENCE_SUFFIX;
        $qualifier  = $table->getQualifier();

        if ($tableName->isQuoted() || $columnName->isQuoted()) {
            return new OptionallyQualifiedName(Identifier::quoted($value), $qualifier);
        }

        return new OptionallyQualifiedName(Identifier::unquoted($value), $qualifier);
    }

    /**
     * The owned sequence named in the {@see self::OWNED_SEQUENCE_OPTION}
     * platform option of an introspected column, or null when the column has
     * none.
     *
     * @param array<string, mixed> $column The column's properties, as {@see Column::toArray()} returns them.
     */
    public static function getIntrospectedOwnedSequenceName(array $column): OptionallyQualifiedName|null
    {
        $option = $column[self::OWNED_SEQUENCE_OPTION] ?? null;

        if (! is_string($option)) {
            return null;
        }

        return self::parseSequenceName($option);
    }

    /**
     * The owned sequence that a column default draws from, or null when the
     * default is not `nextval('<sequence>')` for a sequence with the owned
     * suffix.
     */
    public function parseOwnedSequenceDefault(string $default): OptionallyQualifiedName|null
    {
        if (preg_match('/^\s*nextval\s*\(\s*\'((?:[^\']|\'\')*)\'\s*\)\s*$/is', $default, $matches) !== 1) {
            return null;
        }

        $name = self::parseSequenceName(str_replace("''", "'", $matches[1]));

        if ($name === null || ! str_ends_with($name->getUnqualifiedName()->getValue(), self::OWNED_SEQUENCE_SUFFIX)) {
            return null;
        }

        return $name;
    }

    /**
     * Column sets that a foreign key references become inline UNIQUE
     * constraints, because DuckDB accepts a foreign key only against a
     * constraint. Every other index and unique constraint becomes a named
     * CREATE INDEX statement, because DuckDB discards constraint names.
     *
     * @param list<ColumnProperties> $columns
     * @param CreateTableParameters  $options
     *
     * @return list<string>
     */
    // phpcs:ignore PSR2.Methods.MethodDeclaration.Underscore -- name defined by AbstractPlatform
    protected function _getCreateTableSQL(string $name, array $columns, array $options = []): array
    {
        $this->validateCreateTableOptions($options, __METHOD__);

        foreach ($columns as $position => $column) {
            if (! isset($this->ownedSequenceDefaults[$position])) {
                continue;
            }

            $column['default'] = self::rawDefault($this->ownedSequenceDefaults[$position]);

            $columns[$position] = $column;
        }

        $indexes           = $options['indexes'] ?? [];
        $uniqueConstraints = $options['uniqueConstraints'] ?? [];
        $definitions       = [$this->getColumnDeclarationListSQL($columns)];

        if (! empty($options['primary'])) {
            $definitions[] = 'PRIMARY KEY (' . implode(', ', array_unique($options['primary'])) . ')';
        }

        $inlineUnique = [];

        foreach ($indexes as $index) {
            $columnSet = self::uniqueIndexColumnSet($index);

            if ($columnSet === null || ! isset($this->referencedColumnLists[$columnSet])) {
                continue;
            }

            $inlineUnique[$columnSet] = true;
        }

        foreach ($uniqueConstraints as $constraint) {
            $columnSet = self::columnSetKey($constraint->getColumnNames());

            if (! isset($this->referencedColumnLists[$columnSet])) {
                continue;
            }

            $inlineUnique[$columnSet] = true;
        }

        foreach (array_keys($inlineUnique) as $columnSet) {
            $definitions[] = 'UNIQUE (' . $this->columnListSQL($this->referencedColumnLists[$columnSet]) . ')';
        }

        if (! $this->omitForeignKeys) {
            foreach ($options['foreignKeys'] ?? [] as $foreignKey) {
                $definitions[] = $this->getForeignKeyDeclarationSQL($foreignKey);
            }
        }

        $check = $this->getCheckDeclarationSQL($columns);

        if ($check !== '') {
            $definitions[] = $check;
        }

        $sql = ['CREATE TABLE ' . $name . ' (' . implode(', ', $definitions) . ')'];

        foreach ($indexes as $index) {
            $columnSet = self::uniqueIndexColumnSet($index);

            if ($columnSet !== null && isset($inlineUnique[$columnSet])) {
                continue;
            }

            $sql[] = $this->getCreateIndexSQL($index, $name);
        }

        foreach ($uniqueConstraints as $constraint) {
            if (isset($inlineUnique[self::columnSetKey($constraint->getColumnNames())])) {
                continue;
            }

            $sql[] = $this->getCreateIndexSQL($this->uniqueConstraintToIndex($constraint, $name), $name);
        }

        return $sql;
    }

    /** DuckDB cannot create partial indexes, so an index with a predicate fails at generation. */
    protected function getPartialIndexSQL(Index $index): string
    {
        if ($index->getPredicate() === null) {
            return '';
        }

        throw new NotSupported(sprintf(
            'Index "%s" has a WHERE clause, but DuckDB 2.0 cannot create partial indexes.',
            $index->getObjectName()->toString(),
        ));
    }

    /** DuckDB creates a foreign key only together with its table. */
    public function getCreateForeignKeySQL(ForeignKeyConstraint $foreignKey, string $table): string
    {
        throw new NotSupported(sprintf(
            'DuckDB 2.0 cannot add a foreign key to the existing table %s; it creates foreign keys only together '
                . 'with their table.',
            $table,
        ));
    }

    /** DuckDB drops a foreign key only together with its table. */
    public function getDropForeignKeySQL(string $foreignKey, string $table): string
    {
        throw new NotSupported(sprintf(
            'DuckDB 2.0 cannot drop the foreign key %s from table %s; it drops foreign keys only together with '
                . 'their table.',
            $foreignKey,
            $table,
        ));
    }

    /** An index lives in its table's schema, and DuckDB resolves an unqualified index name in the default one. */
    public function getDropIndexSQL(string $name, string $table): string
    {
        if (str_contains($table, '.') && ! str_contains($name, '.')) {
            [$schema] = explode('.', $table);
            $name     = $schema . '.' . $name;
        }

        return parent::getDropIndexSQL($name, $table);
    }

    // phpcs:disable Squiz.Commenting.FunctionComment.InvalidNoReturn -- temporary stub until the schema units land

    /** @return list<string> */
    public function getAlterTableSQL(TableDiff $diff): array
    {
        throw NotSupported::new(__METHOD__);
    }

    // phpcs:enable

    /** DuckDB has no sequence cache, so the cache size is not rendered. */
    public function getCreateSequenceSQL(Sequence $sequence): string
    {
        return 'CREATE SEQUENCE ' . $sequence->getObjectName()->toSQL($this)
            . ' INCREMENT BY ' . $sequence->getAllocationSize()
            . ' MINVALUE ' . $sequence->getInitialValue()
            . ' START WITH ' . $sequence->getInitialValue();
    }

    public function getSequenceNextValSQL(string $sequence): string
    {
        return 'SELECT nextval(' . $this->quoteStringLiteral($sequence) . ')';
    }

    public function getListViewsSQL(string $database): string
    {
        return 'SELECT view_name, sql FROM duckdb_views() WHERE NOT internal AND database_name = CURRENT_DATABASE()';
    }

    /** DuckDB always runs transactions under snapshot isolation, which DBAL calls REPEATABLE READ. */
    public function getDefaultTransactionIsolationLevel(): TransactionIsolationLevel
    {
        return TransactionIsolationLevel::REPEATABLE_READ;
    }

    /**
     * DuckDB has no statement that changes the isolation level. Selecting
     * the level it always uses runs a no-op statement; any other level fails.
     */
    public function getSetTransactionIsolationSQL(TransactionIsolationLevel $level): string
    {
        if ($level !== TransactionIsolationLevel::REPEATABLE_READ) {
            throw new NotSupported(sprintf(
                'DuckDB 2.0 runs every transaction under snapshot isolation (REPEATABLE READ); '
                    . 'isolation level %s is not supported.',
                $this->_getTransactionIsolationLevelSQL($level),
            ));
        }

        return $this->getDummySelectSQL();
    }

    public function supportsSequences(): bool
    {
        return true;
    }

    public function supportsIdentityColumns(): bool
    {
        return false;
    }

    /** Savepoint statements are intercepted by the driver, which flattens nested transactions. */
    public function supportsSavepoints(): bool
    {
        return true;
    }

    public function supportsReleaseSavepoints(): bool
    {
        return true;
    }

    public function supportsSchemas(): bool
    {
        return true;
    }

    public function supportsCommentOnStatement(): bool
    {
        return true;
    }

    public function supportsPartialIndexes(): bool
    {
        return false;
    }

    protected function getVarcharTypeDeclarationSQLSnippet(int|null $length): string
    {
        return 'VARCHAR';
    }

    protected function getCharTypeDeclarationSQLSnippet(int|null $length): string
    {
        return 'VARCHAR';
    }

    protected function getBinaryTypeDeclarationSQLSnippet(int|null $length): string
    {
        return 'BLOB';
    }

    protected function getVarbinaryTypeDeclarationSQLSnippet(int|null $length): string
    {
        return 'BLOB';
    }

    /**
     * Maps the type names DuckDB reports in its catalog. Types whose DBAL
     * declaration would differ from the DuckDB type map to the opaque
     * `duckdb_native` type, which keeps the DuckDB declaration, so that
     * introspection never proposes changing a column's type.
     */
    protected function initializeDoctrineTypeMappings(): void
    {
        NativeType::register();

        $this->doctrineTypeMapping = [
            'array' => NativeType::NAME,
            'bigint' => Types::BIGINT,
            'bignum' => NativeType::NAME,
            'bit' => NativeType::NAME,
            'blob' => Types::BLOB,
            'boolean' => Types::BOOLEAN,
            'date' => Types::DATE_MUTABLE,
            'decimal' => Types::DECIMAL,
            'double' => Types::FLOAT,
            'enum' => NativeType::NAME,
            'float' => Types::SMALLFLOAT,
            'geometry' => NativeType::NAME,
            'hugeint' => NativeType::NAME,
            'integer' => Types::INTEGER,
            'interval' => NativeType::NAME,
            'json' => Types::JSON,
            'list' => NativeType::NAME,
            'map' => NativeType::NAME,
            'smallint' => Types::SMALLINT,
            'struct' => NativeType::NAME,
            'time' => Types::TIME_MUTABLE,
            'time with time zone' => NativeType::NAME,
            'timestamp' => Types::DATETIME_MUTABLE,
            'timestamp with time zone' => Types::DATETIMETZ_MUTABLE,
            'timestamp_ms' => NativeType::NAME,
            'timestamp_ns' => NativeType::NAME,
            'timestamp_s' => NativeType::NAME,
            'tinyint' => NativeType::NAME,
            'ubigint' => NativeType::NAME,
            'uhugeint' => NativeType::NAME,
            'uinteger' => NativeType::NAME,
            'union' => NativeType::NAME,
            'usmallint' => NativeType::NAME,
            'utinyint' => NativeType::NAME,
            'uuid' => Types::GUID,
            'varchar' => Types::STRING,
            'variant' => NativeType::NAME,
        ];
    }

    protected function createReservedKeywordsList(): KeywordList
    {
        return new DuckDBKeywords();
    }

    public function createSchemaManager(Connection $connection): DuckDBSchemaManager
    {
        return new DuckDBSchemaManager($connection, $this);
    }

    public function createMetadataProvider(Connection $connection): DuckDBMetadataProvider
    {
        return new DuckDBMetadataProvider($connection, $this);
    }

    private static function rowLocksNotSupported(string $request): NotSupported
    {
        return new NotSupported(sprintf(
            'DuckDB does not support row locks, so %s cannot be honored. '
                . 'Use optimistic locking with a version column instead.',
            $request,
        ));
    }

    /**
     * Parses a sequence name as SQL writes it, such as `seq`, `"Odd Seq"` or
     * `s.seq`, into its parts. Returns null for anything else.
     */
    private static function parseSequenceName(string $sql): OptionallyQualifiedName|null
    {
        $part = '\s*(?:"((?:[^"]|"")+)"|([^\s".]+))\s*';

        if (preg_match('/^' . $part . '(?:\.' . $part . ')?$/s', $sql, $matches) !== 1) {
            return null;
        }

        $first  = self::sequenceNamePart($matches[1] ?? '', $matches[2] ?? '');
        $second = self::sequenceNamePart($matches[3] ?? '', $matches[4] ?? '');

        if ($first === null) {
            return null;
        }

        if ($second === null) {
            return new OptionallyQualifiedName($first, null);
        }

        return new OptionallyQualifiedName($second, $first);
    }

    /** One part of a sequence name: the inside of a quoted identifier, or else a bare one. */
    private static function sequenceNamePart(string $quoted, string $bare): Identifier|null
    {
        if ($quoted !== '') {
            return Identifier::quoted(str_replace('""', '"', $quoted));
        }

        if ($bare !== '') {
            return Identifier::unquoted($bare);
        }

        return null;
    }

    /** A default expression that renders the given SQL as written. */
    private static function rawDefault(string $sql): DefaultExpression
    {
        return new class ($sql) implements DefaultExpression {
            public function __construct(private readonly string $sql)
            {
            }

            public function toSQL(AbstractPlatform $platform): string
            {
                return $this->sql;
            }
        };
    }

    private static function assertReferentialActionsSupported(Table $table): void
    {
        foreach ($table->getForeignKeys() as $foreignKey) {
            $actions = [
                'ON UPDATE' => $foreignKey->getOnUpdateAction(),
                'ON DELETE' => $foreignKey->getOnDeleteAction(),
            ];

            foreach ($actions as $event => $action) {
                if ($action === ReferentialAction::NO_ACTION || $action === ReferentialAction::RESTRICT) {
                    continue;
                }

                throw ForeignKeyActionNotSupported::new(
                    $table->getObjectName()->toString(),
                    self::describeForeignKey($foreignKey),
                    $event . ' ' . $action->toSQL(),
                );
            }
        }
    }

    private static function describeForeignKey(ForeignKeyConstraint $foreignKey): string
    {
        $name = $foreignKey->getObjectName();

        if ($name !== null) {
            return $name->toString();
        }

        $columns = array_map(
            static fn (UnqualifiedName $column): string => $column->toString(),
            $foreignKey->getReferencingColumnNames(),
        );

        return 'unnamed (' . implode(', ', $columns) . ')';
    }

    /**
     * Orders tables so that every table follows the tables it references.
     * Self-references and references to tables outside the list are ignored.
     *
     * @param list<Table> $tables
     *
     * @return list<Table>
     */
    private static function sortParentsFirst(array $tables): array
    {
        $byKey = [];

        foreach ($tables as $table) {
            $byKey[self::tableKey($table->getObjectName())] = $table;
        }

        $state  = [];
        $path   = [];
        $sorted = [];

        foreach (array_keys($byKey) as $key) {
            self::visit($key, $byKey, $state, $path, $sorted);
        }

        return $sorted;
    }

    /**
     * @param array<string, Table>    $byKey
     * @param array<string, int|true> $state  The table's position in $path while it is visited, true once sorted.
     * @param list<string>            $path   The tables being visited, outermost first.
     * @param list<Table>             $sorted
     */
    private static function visit(string $key, array $byKey, array &$state, array &$path, array &$sorted): void
    {
        $position = $state[$key] ?? null;

        if ($position === true) {
            return;
        }

        if ($position !== null) {
            $tables = [$byKey[$key]->getObjectName()->toString()];

            foreach (array_slice($path, $position + 1) as $member) {
                $tables[] = $byKey[$member]->getObjectName()->toString();
            }

            throw ForeignKeyCycle::new($tables);
        }

        $state[$key] = count($path);
        $path[]      = $key;

        foreach ($byKey[$key]->getForeignKeys() as $foreignKey) {
            $referenced = self::tableKey($foreignKey->getReferencedTableName());

            if ($referenced === $key || ! isset($byKey[$referenced])) {
                continue;
            }

            self::visit($referenced, $byKey, $state, $path, $sorted);
        }

        $path        = array_slice($path, 0, -1);
        $state[$key] = true;
        $sorted[]    = $byKey[$key];
    }

    /**
     * Collects, per referenced table, the column lists that foreign keys
     * reference and that are not the table's primary key.
     *
     * @param list<Table> $tables
     *
     * @return array<string, array<string, non-empty-list<UnqualifiedName>>>
     */
    private static function collectReferencedColumnLists(array $tables): array
    {
        $primaryKeys = [];

        foreach ($tables as $table) {
            $primaryKey = $table->getPrimaryKeyConstraint();

            if ($primaryKey === null) {
                continue;
            }

            $primaryKeys[self::tableKey($table->getObjectName())] = self::columnSetKey($primaryKey->getColumnNames());
        }

        $lists = [];

        foreach ($tables as $table) {
            foreach ($table->getForeignKeys() as $foreignKey) {
                $referencedTable = self::tableKey($foreignKey->getReferencedTableName());
                $columns         = $foreignKey->getReferencedColumnNames();
                $columnSet       = self::columnSetKey($columns);

                if (($primaryKeys[$referencedTable] ?? null) === $columnSet) {
                    continue;
                }

                $lists[$referencedTable][$columnSet] ??= $columns;
            }
        }

        return $lists;
    }

    /** DuckDB matches identifiers case-insensitively, quoted or not. */
    private static function tableKey(OptionallyQualifiedName $name): string
    {
        $key       = strtolower($name->getUnqualifiedName()->getValue());
        $qualifier = $name->getQualifier();

        if ($qualifier === null) {
            return $key;
        }

        return strtolower($qualifier->getValue()) . '.' . $key;
    }

    /** @param list<UnqualifiedName> $columns */
    private static function columnSetKey(array $columns): string
    {
        $names = array_map(
            static fn (UnqualifiedName $column): string => strtolower($column->getIdentifier()->getValue()),
            $columns,
        );

        sort($names);

        return implode("\0", $names);
    }

    /** The column set of a unique index without predicate, or null for any other index. */
    private static function uniqueIndexColumnSet(Index $index): string|null
    {
        if ($index->getType() !== IndexType::UNIQUE || $index->getPredicate() !== null) {
            return null;
        }

        return self::columnSetKey(array_map(
            static fn (IndexedColumn $column): UnqualifiedName => $column->getColumnName(),
            $index->getIndexedColumns(),
        ));
    }

    /** @param non-empty-list<UnqualifiedName> $columns */
    private function columnListSQL(array $columns): string
    {
        return implode(', ', array_map(
            fn (UnqualifiedName $column): string => $column->toSQL($this),
            $columns,
        ));
    }

    /** An unnamed unique constraint gets a name in DBAL's style, because a DuckDB index needs one. */
    private function uniqueConstraintToIndex(UniqueConstraint $constraint, string $table): Index
    {
        $columns = $constraint->getColumnNames();
        $name    = $constraint->getObjectName();

        if ($name === null) {
            $parts = [$table];

            foreach ($columns as $column) {
                $parts[] = $column->getIdentifier()->getValue();
            }

            $hash = implode('', array_map(static fn (string $part): string => dechex(crc32($part)), $parts));
            $name = UnqualifiedName::unquoted(strtoupper(substr('uniq_' . $hash, 0, $this->getMaxIdentifierLength())));
        }

        return Index::editor()
            ->setName($name)
            ->setType(IndexType::UNIQUE)
            ->setColumnNames(...$columns)
            ->create();
    }
}
