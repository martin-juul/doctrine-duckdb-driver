<?php

declare(strict_types=1);

namespace MartinJuul\DBAL\DuckDB;

use Doctrine\DBAL\Driver\API\ExceptionConverter as ExceptionConverterInterface;
use Doctrine\DBAL\Driver\Exception;
use Doctrine\DBAL\Exception\ConnectionException;
use Doctrine\DBAL\Exception\ConstraintViolationException;
use Doctrine\DBAL\Exception\DatabaseObjectExistsException;
use Doctrine\DBAL\Exception\DatabaseObjectNotFoundException;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\InvalidFieldNameException;
use Doctrine\DBAL\Exception\NonUniqueFieldNameException;
use Doctrine\DBAL\Exception\NotNullConstraintViolationException;
use Doctrine\DBAL\Exception\SchemaDoesNotExist;
use Doctrine\DBAL\Exception\ServerException;
use Doctrine\DBAL\Exception\SyntaxErrorException;
use Doctrine\DBAL\Exception\TableExistsException;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Doctrine\DBAL\Exception\TransactionRolledBack;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Query;
use DuckDB\ErrorType;
use MartinJuul\DBAL\DuckDB\Driver\Exception\CommitFailed;
use MartinJuul\DBAL\DuckDB\Driver\Exception\DriverError;
use MartinJuul\DBAL\DuckDB\Driver\Exception\RollbackOnly;
use MartinJuul\DBAL\DuckDB\Exception\EarlyForeignKeyCheckViolation;

use function preg_match;
use function str_contains;

/**
 * Converts driver exceptions by ext-duckdb's error category first, then by
 * DuckDB 2.0's message wording. Each message pattern has a functional test,
 * so a DuckDB release that rewords one fails loudly.
 */
final class ExceptionConverter implements ExceptionConverterInterface
{
    private const string LOCK_CONFLICT_EXPLANATION = 'Another process holds this database file open. '
        . 'Across processes DuckDB lets one process open it for writing or any number of processes open it '
        . 'read-only, never both at once.';

    private const string CONFIGURATION_CONFLICT_EXPLANATION = 'Connections in one process share a single '
        . 'DuckDB instance per database file, so every connection to this file must pass the same '
        . 'driverOptions as the connections already open on it.';

    private const string FOREIGN_KEY_TARGET_EXPLANATION = 'DuckDB needs a PRIMARY KEY or UNIQUE constraint on the '
        . 'referenced columns, in the foreign key\'s column order; a unique index does not count. The package '
        . 'declares that constraint when the referenced table is created or rebuilt through a schema comparison, '
        . 'so create both tables in one migration or let the comparator rebuild the referenced table.';

    public function convert(Exception $exception, Query|null $query): DriverException
    {
        $message = $exception->getMessage();

        // The transaction can only end in a rollback, or already has (KTD2, KTD3).
        if ($exception instanceof RollbackOnly) {
            return new TransactionRolledBack($exception, $query);
        }

        if ($exception instanceof CommitFailed) {
            if (self::isUniqueViolation($message)) {
                return new UniqueConstraintViolationException($exception, $query);
            }

            return new TransactionRolledBack($exception, $query);
        }

        return match (ErrorType::tryFrom($exception->getCode())) {
            ErrorType::Constraint => self::convertConstraintViolation($exception, $message, $query),
            ErrorType::Transaction => self::convertTransactionError($exception, $message, $query),
            ErrorType::Catalog => self::convertCatalogError($exception, $message, $query),
            ErrorType::Dependency => new ServerException($exception, $query),
            ErrorType::Parser => new SyntaxErrorException($exception, $query),
            ErrorType::Binder => self::convertBinderError($exception, $message, $query),
            ErrorType::Connection => self::convertConnectionError($exception, $message, $query),
            ErrorType::Io => self::convertIoError($exception, $message, $query),
            default => new DriverException($exception, $query),
        };
    }

    private static function convertConstraintViolation(
        Exception $exception,
        string $message,
        Query|null $query,
    ): ConstraintViolationException {
        if (self::isUniqueViolation($message)) {
            return new UniqueConstraintViolationException($exception, $query);
        }

        if (str_contains($message, 'Violates foreign key constraint')) {
            if (str_contains($message, 'foreign key limitations')) {
                return new EarlyForeignKeyCheckViolation($exception, $query);
            }

            return new ForeignKeyConstraintViolationException($exception, $query);
        }

        if (str_contains($message, 'NOT NULL constraint failed')) {
            return new NotNullConstraintViolationException($exception, $query);
        }

        return new ConstraintViolationException($exception, $query);
    }

    private static function convertTransactionError(
        Exception $exception,
        string $message,
        Query|null $query,
    ): DriverException {
        // A commit-time uniqueness conflict between concurrent transactions.
        if (self::isUniqueViolation($message)) {
            return new UniqueConstraintViolationException($exception, $query);
        }

        // A statement-time write-write conflict: retrying the transaction can succeed.
        if (str_contains($message, 'Conflict on ') || str_contains($message, 'write-write conflict')) {
            return new DeadlockException($exception, $query);
        }

        return new DriverException($exception, $query);
    }

    private static function convertCatalogError(
        Exception $exception,
        string $message,
        Query|null $query,
    ): DriverException {
        // "Table with name s.t does not exist because schema s does not exist." names a missing table too.
        if (preg_match('/\b(?:Table|View|Sequence|Index) with name .+ does not exist[!.]/', $message) === 1) {
            return new TableNotFoundException($exception, $query);
        }

        if (preg_match('/\bSchema with name .+ does not exist!/', $message) === 1) {
            return new SchemaDoesNotExist($exception, $query);
        }

        // Types, macros and functions, which DBAL has no dedicated exception for.
        if (preg_match('/ with name .+ does not exist!/', $message) === 1) {
            return new DatabaseObjectNotFoundException($exception, $query);
        }

        if (preg_match('/\b(?:Table|View|Sequence|Index) with name .+ already exists!/', $message) === 1) {
            return new TableExistsException($exception, $query);
        }

        // Schemas, types, macros and functions.
        if (preg_match('/ with name .+ already exists!/', $message) === 1) {
            return new DatabaseObjectExistsException($exception, $query);
        }

        return new DriverException($exception, $query);
    }

    private static function convertBinderError(
        Exception $exception,
        string $message,
        Query|null $query,
    ): DriverException {
        if (
            preg_match('/Referenced column ".+" not found/', $message) === 1
            || str_contains($message, 'does not have a column with name')
        ) {
            return new InvalidFieldNameException($exception, $query);
        }

        if (str_contains($message, 'Ambiguous reference to column name')) {
            return new NonUniqueFieldNameException($exception, $query);
        }

        if (
            str_contains($message, 'Failed to create foreign key:')
            && str_contains($message, 'primary key or unique constraint')
        ) {
            return new DriverException(self::explain($exception, self::FOREIGN_KEY_TARGET_EXPLANATION), $query);
        }

        return new DriverException($exception, $query);
    }

    private static function convertConnectionError(
        Exception $exception,
        string $message,
        Query|null $query,
    ): ConnectionException {
        if (str_contains($message, 'same database file with a different configuration')) {
            return new ConnectionException(
                self::explain($exception, self::CONFIGURATION_CONFLICT_EXPLANATION),
                $query,
            );
        }

        return new ConnectionException($exception, $query);
    }

    private static function convertIoError(
        Exception $exception,
        string $message,
        Query|null $query,
    ): DriverException {
        if (str_contains($message, 'Could not set lock on file')) {
            return new ConnectionException(
                self::explain($exception, self::LOCK_CONFLICT_EXPLANATION),
                $query,
            );
        }

        return new DriverException($exception, $query);
    }

    private static function isUniqueViolation(string $message): bool
    {
        return str_contains($message, 'violates primary key constraint')
            || str_contains($message, 'violates unique constraint')
            || str_contains($message, 'PRIMARY KEY or UNIQUE constraint violation');
    }

    /** Appends an explanation to the DuckDB message, keeping the original exception as previous. */
    private static function explain(Exception $exception, string $explanation): DriverError
    {
        return new DriverError(
            $exception->getMessage() . "\n" . $explanation,
            $exception->getSQLState(),
            $exception->getCode(),
            $exception,
        );
    }
}
