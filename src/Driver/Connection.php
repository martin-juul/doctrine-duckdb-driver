<?php

declare(strict_types=1);

namespace MartinJuul\DBAL\DuckDB\Driver;

use Doctrine\DBAL\Driver\Connection as ConnectionInterface;
use DuckDB\Connection as NativeConnection;
use DuckDB\Database;
use DuckDB\Exception as DuckDBException;
use MartinJuul\DBAL\DuckDB\Driver\Exception\DriverError;
use MartinJuul\DBAL\DuckDB\Driver\Exception\IdentityColumnsNotSupported;

use function DuckDB\version;
use function str_replace;

final class Connection implements ConnectionInterface
{
    public function __construct(
        private readonly Database $database,
        private readonly NativeConnection $connection,
    ) {
    }

    public function prepare(string $sql): Statement
    {
        try {
            return new Statement($this->connection->prepare($sql));
        } catch (DuckDBException $e) {
            throw DriverError::fromDuckDB($e);
        }
    }

    public function query(string $sql): Result
    {
        try {
            return new Result($this->connection->query($sql));
        } catch (DuckDBException $e) {
            throw DriverError::fromDuckDB($e);
        }
    }

    public function quote(string $value): string
    {
        return "'" . str_replace("'", "''", $value) . "'";
    }

    public function exec(string $sql): int
    {
        try {
            return $this->connection->query($sql)->rowsChanged();
        } catch (DuckDBException $e) {
            throw DriverError::fromDuckDB($e);
        }
    }

    public function lastInsertId(): never
    {
        throw IdentityColumnsNotSupported::new();
    }

    public function beginTransaction(): void
    {
        try {
            $this->connection->beginTransaction();
        } catch (DuckDBException $e) {
            throw DriverError::fromDuckDB($e);
        }
    }

    public function commit(): void
    {
        try {
            $this->connection->commit();
        } catch (DuckDBException $e) {
            throw DriverError::fromDuckDB($e);
        }
    }

    public function rollBack(): void
    {
        try {
            $this->connection->rollBack();
        } catch (DuckDBException $e) {
            throw DriverError::fromDuckDB($e);
        }
    }

    public function getNativeConnection(): NativeConnection
    {
        return $this->connection;
    }

    public function getServerVersion(): string
    {
        return version();
    }

    /** The database this connection belongs to; kept so the instance outlives the connection. */
    public function getDatabase(): Database
    {
        return $this->database;
    }
}
