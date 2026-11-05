<?php

declare(strict_types=1);

namespace MartinJuul\DBAL\DuckDB\Driver;

use Doctrine\DBAL\Driver\Connection as ConnectionInterface;
use Doctrine\DBAL\Driver\Result as ResultInterface;
use Doctrine\DBAL\Driver\Statement as StatementInterface;
use DuckDB\Connection as NativeConnection;
use DuckDB\Database;
use MartinJuul\DBAL\DuckDB\Driver\Exception\IdentityColumnsNotSupported;

use function DuckDB\version;
use function str_replace;

/**
 * A DuckDB connection whose transaction emulates DBAL's nested levels; see
 * TransactionState and StatementClassifier.
 */
final class Connection implements ConnectionInterface
{
    private readonly TransactionState $transaction;

    public function __construct(
        private readonly Database $database,
        private readonly NativeConnection $connection,
    ) {
        $this->transaction = new TransactionState($connection);
    }

    public function prepare(string $sql): StatementInterface
    {
        $kind = StatementClassifier::classify($sql);

        if ($kind !== StatementKind::Plain) {
            return new MarkerStatement($this->transaction, $kind, $sql);
        }

        return $this->transaction->run(
            fn (): Statement => new Statement($this->connection->prepare($sql), $this->transaction),
        );
    }

    public function query(string $sql): ResultInterface
    {
        $kind = StatementClassifier::classify($sql);

        if ($kind !== StatementKind::Plain) {
            $this->transaction->intercept($kind, $sql);

            return new EmptyResult();
        }

        return $this->transaction->run(fn (): Result => new Result($this->connection->query($sql)));
    }

    public function quote(string $value): string
    {
        return self::quoteLiteral($value);
    }

    /** The value as an SQL string literal. */
    public static function quoteLiteral(string $value): string
    {
        return "'" . str_replace("'", "''", $value) . "'";
    }

    public function exec(string $sql): int
    {
        $kind = StatementClassifier::classify($sql);

        if ($kind !== StatementKind::Plain) {
            $this->transaction->intercept($kind, $sql);

            return 0;
        }

        return $this->transaction->run(fn (): int => $this->connection->query($sql)->rowsChanged());
    }

    public function lastInsertId(): never
    {
        throw IdentityColumnsNotSupported::new();
    }

    public function beginTransaction(): void
    {
        $this->transaction->begin();
    }

    /**
     * Commits, or ends the transaction and throws when DuckDB fails to commit
     * or an inner level rolled back; nothing is committed then.
     */
    public function commit(): void
    {
        $this->transaction->commit();
    }

    /** Rolls back, and succeeds when DuckDB already ended the transaction. */
    public function rollBack(): void
    {
        $this->transaction->rollBack();
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
