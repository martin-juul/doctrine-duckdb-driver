<?php

declare(strict_types=1);

namespace MartinJuul\DBAL\DuckDB\Schema;

use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\ColumnDiff;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\Index\IndexedColumn;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Schema\TableDiff;
use Doctrine\DBAL\Types\Type;
use MartinJuul\DBAL\DuckDB\Platform\DuckDBPlatform;

use function array_map;
use function array_merge;
use function array_values;
use function count;
use function get_debug_type;
use function implode;
use function in_array;
use function is_scalar;
use function preg_match;
use function strlen;
use function strtolower;
use function substr;

/**
 * Decides per table whether DuckDB 2.0 can apply a table diff with ALTER
 * statements, and produces them when it can.
 *
 * A native ALTER that fails inside a migration aborts the whole transaction,
 * so the decision is static. DuckDB can add a nullable column, or one with a
 * default, change or drop a default and change comments on any table. It
 * can set NOT NULL, change a type, add a primary key, add a NOT NULL column
 * with a literal default and drop a column only when no index exists and no
 * foreign key references the table, because DuckDB refuses to alter a table
 * that other catalog entries depend on. A NOT NULL column whose default is
 * not a literal, such as CURRENT_TIMESTAMP, always rebuilds. Dropping a
 * column of a table that owns a foreign key always rebuilds, because DuckDB
 * then loses the key's catalog dependency. Everything else, including renames, NOT NULL removal,
 * autoincrement and CHECK changes and every foreign-key change, needs a
 * rebuild.
 *
 * @internal
 *
 * @phpstan-import-type ColumnProperties from Column
 */
final class AlterRouting
{
    public function __construct(private readonly DuckDBPlatform $platform)
    {
    }

    /**
     * The native statements that apply the diff, or null when the table must
     * be rebuilt.
     *
     * @param bool         $referenced           Whether any foreign key, including one of the table itself,
     *                                           references the table.
     * @param list<string> $uniqueConstraintSets The {@see RebuildPlan::columnSetKey()} of each UNIQUE constraint of
     *                                           the table: DuckDB drops those only with the table.
     *
     * @return list<string>|null
     */
    public function nativeSQL(TableDiff $diff, bool $referenced, array $uniqueConstraintSets = []): array|null
    {
        if (! $this->platform->omitsForeignKeys() && $this->changesForeignKeys($diff)) {
            return null;
        }

        $table   = $diff->getOldTable();
        $name    = $table->getObjectName()->toSQL($this->platform);
        $blocked = $referenced || self::hasIndex($table);

        $dropIndexes   = [];
        $alter         = [];
        $createIndexes = [];

        foreach ($diff->getDroppedIndexes() as $index) {
            if ($index->isPrimary() || self::isConstraint($index, $uniqueConstraintSets)) {
                return null;
            }

            $dropIndexes[] = $this->platform->getDropIndexSQL($index->getObjectName()->toSQL($this->platform), $name);
        }

        foreach ($diff->getModifiedIndexes() as $index) {
            if ($index->isPrimary() || self::isConstraint($index, $uniqueConstraintSets)) {
                return null;
            }

            $dropIndexes[]   = $this->platform->getDropIndexSQL($index->getObjectName()->toSQL($this->platform), $name);
            $createIndexes[] = $this->platform->getCreateIndexSQL($index, $name);
        }

        foreach ($diff->getRenamedIndexes() as $oldName => $index) {
            $oldIndex = $table->getIndex($oldName);

            if (self::isConstraint($oldIndex, $uniqueConstraintSets)) {
                return null;
            }

            $dropIndexes[]   = $this->platform->getDropIndexSQL(
                $oldIndex->getObjectName()->toSQL($this->platform),
                $name,
            );
            $createIndexes[] = $this->platform->getCreateIndexSQL($index, $name);
        }

        foreach ($diff->getDroppedColumns() as $column) {
            if (
                $blocked
                || (! $this->platform->omitsForeignKeys() && count($table->getForeignKeys()) > 0)
                || self::inPrimaryKey($table, $column)
                || self::hasCheck($column)
            ) {
                return null;
            }

            $alter[] = 'ALTER TABLE ' . $name . ' DROP COLUMN ' . $this->columnName($column);
        }

        foreach ($diff->getChangedColumns() as $columnDiff) {
            $statements = $this->changeColumn($table, $name, $columnDiff, $blocked);

            if ($statements === null) {
                return null;
            }

            $alter = array_merge($alter, $statements);
        }

        foreach ($diff->getAddedColumns() as $column) {
            $statements = $this->addColumn($name, $column, $blocked);

            if ($statements === null) {
                return null;
            }

            $alter = array_merge($alter, $statements);
        }

        foreach ($diff->getAddedIndexes() as $index) {
            if (! $index->isPrimary()) {
                $createIndexes[] = $this->platform->getCreateIndexSQL($index, $name);

                continue;
            }

            if ($blocked) {
                return null;
            }

            $alter[] = 'ALTER TABLE ' . $name . ' ADD PRIMARY KEY (' . implode(', ', $index->getQuotedColumns($this->platform))
                . ')';
        }

        return array_merge($dropIndexes, $alter, $createIndexes);
    }

    /**
     * Routes table diffs without the context a comparison provides: only the
     * table's own foreign keys count as references, and every rebuild covers
     * only its own table, built by applying the diff to the old table.
     *
     * @param array<TableDiff> $diffs
     */
    public function plan(array $diffs): RebuildPlan
    {
        $native   = [];
        $rebuilds = [];

        foreach ($diffs as $diff) {
            $old = $diff->getOldTable();
            $key = RebuildPlan::tableKey($old->getObjectName());
            $sql = $this->nativeSQL($diff, $this->platform->omitsForeignKeys() ? false : self::referencesItself($old));

            if ($sql !== null) {
                $native[$key] = $sql;

                continue;
            }

            $rebuilds[$key] = RebuildPlan::rebuild($old, self::applyDiff($diff), $diff);
        }

        return new RebuildPlan($native, $rebuilds);
    }

    /**
     * The table that results from applying the diff to its old table. Columns
     * keep their position; added columns follow. Column objects are taken as
     * they are, so their platform options survive.
     */
    public static function applyDiff(TableDiff $diff): Table
    {
        $old = $diff->getOldTable();

        $dropped = [];

        foreach ($diff->getDroppedColumns() as $column) {
            $dropped[RebuildPlan::columnKey($column)] = true;
        }

        $changed = [];

        foreach ($diff->getChangedColumns() as $columnDiff) {
            $changed[RebuildPlan::columnKey($columnDiff->getOldColumn())] = $columnDiff->getNewColumn();
        }

        $columns = [];

        foreach ($old->getColumns() as $column) {
            $key = RebuildPlan::columnKey($column);

            if (isset($dropped[$key])) {
                continue;
            }

            $columns[] = $changed[$key] ?? $column;
        }

        foreach ($diff->getAddedColumns() as $column) {
            $columns[] = $column;
        }

        $primaryKey = $old->getPrimaryKeyConstraint();
        $indexes    = [];

        foreach ($old->getIndexes() as $index) {
            if ($index->isPrimary()) {
                continue;
            }

            $indexes[strtolower($index->getName())] = $index;
        }

        foreach ($diff->getDroppedIndexes() as $index) {
            if ($index->isPrimary()) {
                $primaryKey = null;

                continue;
            }

            unset($indexes[strtolower($index->getName())]);
        }

        foreach ($diff->getRenamedIndexes() as $oldName => $index) {
            unset($indexes[strtolower($oldName)]);

            $indexes[strtolower($index->getName())] = $index;
        }

        foreach (array_merge($diff->getModifiedIndexes(), $diff->getAddedIndexes()) as $index) {
            if ($index->isPrimary()) {
                $primaryKey = PrimaryKeyConstraint::editor()
                    ->setColumnNames(...array_map(
                        static fn (IndexedColumn $column) => $column->getColumnName(),
                        $index->getIndexedColumns(),
                    ))
                    ->create();

                continue;
            }

            $indexes[strtolower($index->getName())] = $index;
        }

        $foreignKeys = [];

        foreach ($old->getForeignKeys() as $foreignKey) {
            if (in_array($foreignKey, $diff->getDroppedForeignKeys(), true)) {
                continue;
            }

            $foreignKeys[] = $foreignKey;
        }

        foreach ($diff->getAddedForeignKeys() as $foreignKey) {
            $foreignKeys[] = $foreignKey;
        }

        return $old->edit()
            ->setColumns(...$columns)
            ->setIndexes(...array_values($indexes))
            ->setPrimaryKeyConstraint($primaryKey)
            ->setForeignKeyConstraints(...$foreignKeys)
            ->create();
    }

    /** Whether one of the table's foreign keys references the table itself. */
    public static function referencesItself(Table $table): bool
    {
        $key = RebuildPlan::tableKey($table->getObjectName());

        foreach ($table->getForeignKeys() as $foreignKey) {
            if (RebuildPlan::tableKey($foreignKey->getReferencedTableName()) === $key) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string>|null */
    private function changeColumn(Table $table, string $name, ColumnDiff $columnDiff, bool $blocked): array|null
    {
        $old = $columnDiff->getOldColumn();
        $new = $columnDiff->getNewColumn();

        if (
            $columnDiff->hasNameChanged()
            || $old->getAutoincrement() !== $new->getAutoincrement()
            || ! self::checksEqual($old, $new)
            || $old->getColumnDefinition() !== null
            || $new->getColumnDefinition() !== null
            || ($old->getNotnull() && ! $new->getNotnull())
        ) {
            return null;
        }

        $column = $this->columnName($new);
        $prefix = 'ALTER TABLE ' . $name . ' ALTER COLUMN ' . $column;
        $sql    = [];
        $type   = $this->typeSQL($new);

        if ($this->typeSQL($old) !== $type) {
            if ($blocked || self::inPrimaryKey($table, $old) || self::inForeignKey($table, $old)) {
                return null;
            }

            $sql[] = $prefix . ' TYPE ' . $type;
        }

        $default = $this->defaultSQL($new);

        if ($this->defaultSQL($old) !== $default) {
            $sql[] = $default === '' ? $prefix . ' DROP DEFAULT' : $prefix . ' SET DEFAULT ' . $default;
        }

        if (! $old->getNotnull() && $new->getNotnull()) {
            if ($blocked) {
                return null;
            }

            $sql[] = $prefix . ' SET NOT NULL';
        }

        if ($old->getComment() !== $new->getComment()) {
            $sql[] = $this->platform->getCommentOnColumnSQL($name, $column, $new->getComment());
        }

        return $sql;
    }

    /** @return list<string>|null */
    private function addColumn(string $name, Column $column, bool $blocked): array|null
    {
        if (
            $column->getAutoincrement()
            || self::hasCheck($column)
            || $column->getColumnDefinition() !== null
            || (
                $column->getNotnull()
                && ($column->getDefault() === null || $blocked || ! self::isLiteral($this->defaultSQL($column)))
            )
        ) {
            return null;
        }

        $columnName = $this->columnName($column);
        $sql        = [
            'ALTER TABLE ' . $name . ' ADD COLUMN '
                . $this->platform->getColumnDeclarationSQL($columnName, $this->columnArray($column)),
        ];

        if ($column->getComment() !== '') {
            $sql[] = $this->platform->getCommentOnColumnSQL($name, $columnName, $column->getComment());
        }

        return $sql;
    }

    private function changesForeignKeys(TableDiff $diff): bool
    {
        return count($diff->getAddedForeignKeys()) > 0
            || count($diff->getModifiedForeignKeys()) > 0
            || count($diff->getDroppedForeignKeys()) > 0;
    }

    private function columnName(Column $column): string
    {
        return $column->getObjectName()->toSQL($this->platform);
    }

    /** @return ColumnProperties */
    private function columnArray(Column $column): array
    {
        $properties = $column->toArray(true);

        $properties['name'] = $this->columnName($column);

        return $properties;
    }

    private function typeSQL(Column $column): string
    {
        return Type::getType($column->getTypeName())->getSQLDeclaration($this->columnArray($column), $this->platform);
    }

    /** The default expression, or an empty string when the column has none. */
    private function defaultSQL(Column $column): string
    {
        $declaration = $this->platform->getDefaultValueDeclarationSQL($this->columnArray($column));

        if ($declaration === '' || $declaration === ' DEFAULT NULL') {
            return '';
        }

        return substr($declaration, strlen(' DEFAULT '));
    }

    /**
     * Whether a default expression is a literal. DuckDB adds a NOT NULL column
     * to a table with rows only when its default is one; any other default,
     * such as CURRENT_TIMESTAMP, fails with "Cannot create index with
     * outstanding updates", even when the column is added nullable and then
     * set NOT NULL in the same transaction.
     */
    private static function isLiteral(string $default): bool
    {
        return preg_match('/^(?:-?\d+(?:\.\d+)?(?:E[+-]?\d+)?|\'(?:[^\']|\'\')*\'|true|false)$/i', $default) === 1;
    }

    /** Whether the table has an index other than its primary key; DuckDB refuses most ALTERs then. */
    public static function hasIndex(Table $table): bool
    {
        foreach ($table->getIndexes() as $index) {
            if (! $index->isPrimary()) {
                return true;
            }
        }

        return false;
    }

    /** @param list<string> $uniqueConstraintSets */
    private static function isConstraint(Index $index, array $uniqueConstraintSets): bool
    {
        if (! $index->isUnique()) {
            return false;
        }

        $columns = array_map(
            static fn (IndexedColumn $column) => $column->getColumnName(),
            $index->getIndexedColumns(),
        );

        return in_array(RebuildPlan::columnSetKey($columns), $uniqueConstraintSets, true);
    }

    private static function inPrimaryKey(Table $table, Column $column): bool
    {
        $primaryKey = $table->getPrimaryKeyConstraint();

        if ($primaryKey === null) {
            return false;
        }

        foreach ($primaryKey->getColumnNames() as $name) {
            if (strtolower($name->getIdentifier()->getValue()) === RebuildPlan::columnKey($column)) {
                return true;
            }
        }

        return false;
    }

    private static function inForeignKey(Table $table, Column $column): bool
    {
        foreach ($table->getForeignKeys() as $foreignKey) {
            if (self::foreignKeyUses($foreignKey, $column)) {
                return true;
            }
        }

        return false;
    }

    private static function foreignKeyUses(ForeignKeyConstraint $foreignKey, Column $column): bool
    {
        foreach ($foreignKey->getReferencingColumnNames() as $name) {
            if (strtolower($name->getIdentifier()->getValue()) === RebuildPlan::columnKey($column)) {
                return true;
            }
        }

        return false;
    }

    /** Whether the column declares a CHECK constraint, which ALTER TABLE cannot add or drop. */
    private static function hasCheck(Column $column): bool
    {
        return $column->getMinimumValue() !== null || $column->getMaximumValue() !== null;
    }

    /** Whether both columns declare the same CHECK constraints. */
    public static function checksEqual(Column $a, Column $b): bool
    {
        return self::checkValue($a->getMinimumValue()) === self::checkValue($b->getMinimumValue())
            && self::checkValue($a->getMaximumValue()) === self::checkValue($b->getMaximumValue());
    }

    private static function checkValue(mixed $value): string|null
    {
        if ($value === null) {
            return null;
        }

        return is_scalar($value) ? (string) $value : get_debug_type($value);
    }
}
