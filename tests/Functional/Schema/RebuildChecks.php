<?php

declare(strict_types=1);

namespace MartinJuul\DBAL\DuckDB\Tests\Functional\Schema;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use PHPUnit\Framework\Assert;

use function implode;
use function is_numeric;
use function is_string;
use function sprintf;

/**
 * The checks every rebuild test runs before and after a rebuild: row counts
 * and ordered per-column checksums, foreign keys, indexes and comments as
 * DuckDB's catalog reports them, no staging leftovers, foreign-key
 * enforcement, and owned sequences that continue past the existing IDs.
 */
final class RebuildChecks
{
    /**
     * The row count and, per column, an ordered checksum of the values cast
     * to text, so that a value moved to another column or row changes it.
     *
     * @param list<string> $columns
     *
     * @return array{rows: int, columns: array<string, string>}
     */
    public static function data(Connection $connection, string $table, string $orderBy, array $columns): array
    {
        $checksums = [];

        foreach ($columns as $column) {
            $checksum = $connection->fetchOne(sprintf(
                "SELECT md5(COALESCE(string_agg(COALESCE(CAST(%s AS VARCHAR), '<null>'), '|' ORDER BY %s), '')) FROM %s",
                $column,
                $orderBy,
                $table,
            ));

            Assert::assertIsString($checksum);

            $checksums[$column] = $checksum;
        }

        return [
            'rows' => self::integer($connection->fetchOne('SELECT count(*) FROM ' . $table)),
            'columns' => $checksums,
        ];
    }

    /**
     * The foreign keys of the database, as `table(columns) -> table(columns)`.
     *
     * @return list<string>
     */
    public static function foreignKeys(Connection $connection): array
    {
        $rows = $connection->fetchAllNumeric(<<<'SQL'
            SELECT table_name,
                   array_to_string(constraint_column_names, ', '),
                   referenced_table,
                   array_to_string(referenced_column_names, ', ')
            FROM duckdb_constraints()
            WHERE constraint_type = 'FOREIGN KEY'
            ORDER BY table_name, constraint_index
            SQL);

        $keys = [];

        foreach ($rows as [$table, $columns, $referencedTable, $referencedColumns]) {
            $keys[] = sprintf(
                '%s(%s) -> %s(%s)',
                self::string($table),
                self::string($columns),
                self::string($referencedTable),
                self::string($referencedColumns),
            );
        }

        return $keys;
    }

    /**
     * The indexes created with CREATE INDEX and the UNIQUE constraints, as
     * `table.name UNIQUE (expressions)`.
     *
     * @return list<string>
     */
    public static function indexes(Connection $connection): array
    {
        $indexes = [];

        $rows = $connection->fetchAllNumeric(
            'SELECT table_name, index_name, is_unique, expressions FROM duckdb_indexes() ORDER BY table_name, index_name',
        );

        foreach ($rows as [$table, $name, $unique, $expressions]) {
            $indexes[] = sprintf(
                '%s.%s%s %s',
                self::string($table),
                self::string($name),
                $unique === true ? ' UNIQUE' : '',
                self::string($expressions),
            );
        }

        $rows = $connection->fetchAllNumeric(<<<'SQL'
            SELECT table_name, array_to_string(constraint_column_names, ', ')
            FROM duckdb_constraints()
            WHERE constraint_type = 'UNIQUE'
            ORDER BY table_name, constraint_index
            SQL);

        foreach ($rows as [$table, $columns]) {
            $indexes[] = sprintf('%s UNIQUE (%s)', self::string($table), self::string($columns));
        }

        return $indexes;
    }

    /**
     * The table and column comments, as `table: comment` and
     * `table.column: comment`.
     *
     * @return list<string>
     */
    public static function comments(Connection $connection): array
    {
        $comments = [];

        $rows = $connection->fetchAllNumeric(
            'SELECT table_name, comment FROM duckdb_tables() WHERE comment IS NOT NULL ORDER BY table_name',
        );

        foreach ($rows as [$table, $comment]) {
            $comments[] = self::string($table) . ': ' . self::string($comment);
        }

        $rows = $connection->fetchAllNumeric(<<<'SQL'
            SELECT table_name, column_name, comment
            FROM duckdb_columns()
            WHERE comment IS NOT NULL
            ORDER BY table_name, column_index
            SQL);

        foreach ($rows as [$table, $column, $comment]) {
            $comments[] = self::string($table) . '.' . self::string($column) . ': ' . self::string($comment);
        }

        return $comments;
    }

    /**
     * The CHECK, NOT NULL and default definitions of a table's columns, as
     * DuckDB's catalog reports them.
     *
     * @return list<string>
     */
    public static function constraints(Connection $connection, string $table): array
    {
        $definitions = [];

        $rows = $connection->fetchAllNumeric(
            "SELECT constraint_text FROM duckdb_constraints() WHERE table_name = ? AND constraint_type IN ('CHECK', "
                . "'NOT NULL') ORDER BY constraint_text",
            [$table],
        );

        foreach ($rows as [$text]) {
            $definitions[] = self::string($text);
        }

        $rows = $connection->fetchAllNumeric(
            'SELECT column_name, column_default FROM duckdb_columns() WHERE table_name = ? AND column_default IS NOT '
                . 'NULL ORDER BY column_name',
            [$table],
        );

        foreach ($rows as [$column, $default]) {
            $definitions[] = self::string($column) . ' DEFAULT ' . self::string($default);
        }

        return $definitions;
    }

    /** No staging table is left, and no table's SQL mentions one. */
    public static function assertNoStagingTables(Connection $connection): void
    {
        $sql = $connection->fetchFirstColumn('SELECT table_name || \': \' || sql FROM duckdb_tables()');

        Assert::assertStringNotContainsString('__stage', implode("\n", self::strings($sql)));
    }

    public static function assertForeignKeyRejects(Connection $connection, string $insert): void
    {
        try {
            $connection->executeStatement($insert);
        } catch (ForeignKeyConstraintViolationException) {
            return;
        }

        Assert::fail(sprintf('The foreign key accepted "%s".', $insert));
    }

    /** Inserts a row whose ID comes from the owned sequence and checks it exceeds the IDs present before. */
    public static function assertNextIdExceeds(Connection $connection, string $insertReturningId, int $maximum): void
    {
        $id = self::integer($connection->fetchOne($insertReturningId));

        Assert::assertGreaterThan($maximum, $id);
    }

    public static function maximumId(Connection $connection, string $table): int
    {
        return self::integer($connection->fetchOne('SELECT max(id) FROM ' . $table));
    }

    private static function integer(mixed $value): int
    {
        Assert::assertTrue(is_numeric($value), 'Expected a number.');

        return (int) $value;
    }

    private static function string(mixed $value): string
    {
        Assert::assertIsString($value);

        return $value;
    }

    /**
     * @param list<mixed> $values
     *
     * @return list<string>
     */
    private static function strings(array $values): array
    {
        $strings = [];

        foreach ($values as $value) {
            $strings[] = is_string($value) ? $value : '';
        }

        return $strings;
    }
}
