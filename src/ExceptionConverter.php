<?php

declare(strict_types=1);

namespace MartinJuul\DBAL\DuckDB;

use Doctrine\DBAL\Driver\API\ExceptionConverter as ExceptionConverterInterface;
use Doctrine\DBAL\Driver\Exception;
use Doctrine\DBAL\Exception\ConnectionException;
use Doctrine\DBAL\Exception\ConstraintViolationException;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\InvalidFieldNameException;
use Doctrine\DBAL\Exception\NonUniqueFieldNameException;
use Doctrine\DBAL\Exception\NotNullConstraintViolationException;
use Doctrine\DBAL\Exception\ServerException;
use Doctrine\DBAL\Exception\SyntaxErrorException;
use Doctrine\DBAL\Exception\TableExistsException;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Query;
use DuckDB\ErrorType;
use MartinJuul\DBAL\DuckDB\Driver\Exception\DriverError;
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

    public function convert(Exception $exception, Query|null $query): DriverException
    {
        $message = $exception->getMessage();

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
        if (preg_match('/\b(?:Table|View|Sequence|Index) with name .+ does not exist!/', $message) === 1) {
            return new TableNotFoundException($exception, $query);
        }

        if (preg_match('/\b(?:Table|View|Sequence|Index) with name .+ already exists!/', $message) === 1) {
            return new TableExistsException($exception, $query);
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
