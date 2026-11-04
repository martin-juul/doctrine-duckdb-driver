<?php

declare(strict_types=1);

namespace MartinJuul\DBAL\DuckDB\Platform;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\DateIntervalUnit;
use Doctrine\DBAL\Platforms\Exception\NotSupported;
use Doctrine\DBAL\Platforms\Keywords\KeywordList;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\Schema\Name\UnquotedIdentifierFolding;
use Doctrine\DBAL\Schema\TableDiff;
use Doctrine\DBAL\TransactionIsolationLevel;
use Doctrine\DBAL\Types\Types;

/**
 * Doctrine DBAL platform for DuckDB 2.0.
 *
 * Extends AbstractPlatform directly: Doctrine's tests and ORM branch on
 * instanceof PostgreSQLPlatform/SQLitePlatform, and extending either would
 * pull in that engine's assumptions.
 */
class DuckDBPlatform extends AbstractPlatform
{
    public function __construct()
    {
        parent::__construct(UnquotedIdentifierFolding::NONE);
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

    // phpcs:disable Squiz.Commenting.FunctionComment.InvalidNoReturn -- temporary stub until the schema units land

    /** @return list<string> */
    public function getAlterTableSQL(TableDiff $diff): array
    {
        throw NotSupported::new(__METHOD__);
    }

    // phpcs:enable

    public function getListViewsSQL(string $database): string
    {
        return 'SELECT view_name, sql FROM duckdb_views() WHERE NOT internal AND database_name = CURRENT_DATABASE()';
    }

    public function getSetTransactionIsolationSQL(TransactionIsolationLevel $level): string
    {
        throw NotSupported::new(__METHOD__);
    }

    protected function initializeDoctrineTypeMappings(): void
    {
        $this->doctrineTypeMapping = [
            'bigint' => Types::BIGINT,
            'blob' => Types::BLOB,
            'boolean' => Types::BOOLEAN,
            'date' => Types::DATE_MUTABLE,
            'decimal' => Types::DECIMAL,
            'double' => Types::FLOAT,
            'float' => Types::SMALLFLOAT,
            'integer' => Types::INTEGER,
            'interval' => Types::DATEINTERVAL,
            'json' => Types::JSON,
            'smallint' => Types::SMALLINT,
            'time' => Types::TIME_MUTABLE,
            'timestamp' => Types::DATETIME_MUTABLE,
            'timestamp with time zone' => Types::DATETIMETZ_MUTABLE,
            'uuid' => Types::GUID,
            'varchar' => Types::STRING,
        ];
    }

    protected function createReservedKeywordsList(): KeywordList
    {
        return new DuckDBKeywords();
    }

    // phpcs:disable Squiz.Commenting.FunctionComment.InvalidNoReturn -- temporary stub until the schema units land

    /** @return AbstractSchemaManager<self> */
    public function createSchemaManager(Connection $connection): AbstractSchemaManager
    {
        throw NotSupported::new(__METHOD__);
    }

    // phpcs:enable
}
