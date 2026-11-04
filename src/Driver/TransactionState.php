<?php

declare(strict_types=1);

namespace MartinJuul\DBAL\DuckDB\Driver;

use Closure;
use DuckDB\Connection as NativeConnection;
use DuckDB\Exception as DuckDBException;
use MartinJuul\DBAL\DuckDB\Driver\Exception\CommitFailed;
use MartinJuul\DBAL\DuckDB\Driver\Exception\DriverError;
use MartinJuul\DBAL\DuckDB\Driver\Exception\RollbackOnly;
use MartinJuul\DBAL\DuckDB\Driver\Exception\TransactionStatementRejected;
use Throwable;

use function str_contains;

/**
 * The DuckDB transaction of one driver connection, with nested levels
 * flattened into it.
 *
 * DBAL owns the nesting level and sends savepoint statements for inner
 * levels; this class turns them into no-ops, except that an inner rollback
 * makes the transaction rollback-only. It also tracks a transaction the
 * driver opened itself for a table rebuild, and ends every failed commit
 * here so DBAL receives a transaction that is already rolled back.
 */
final class TransactionState
{
    private bool $active = false;

    private bool $rollbackOnly = false;

    /** Whether the driver opened the transaction at a rebuild begin marker. */
    private bool $driverOwned = false;

    public function __construct(private readonly NativeConnection $connection)
    {
    }

    public function begin(): void
    {
        try {
            $this->connection->beginTransaction();
        } catch (DuckDBException $e) {
            throw DriverError::fromDuckDB($e);
        }

        $this->active = true;
    }

    public function commit(): void
    {
        if ($this->rollbackOnly) {
            $this->rollBackIfOpen();
            $this->reset();

            throw RollbackOnly::nothingCommitted();
        }

        try {
            $this->connection->commit();
        } catch (DuckDBException $e) {
            // An aborted transaction stays open after the failed commit; a commit-time
            // conflict or out-of-memory failure has already ended it.
            $this->rollBackIfOpen();
            $this->reset();

            throw CommitFailed::fromDuckDB($e);
        }

        $this->reset();
    }

    public function rollBack(): void
    {
        try {
            $this->connection->rollBack();
        } catch (DuckDBException $e) {
            if (! self::isNoActiveTransaction($e)) {
                throw DriverError::fromDuckDB($e);
            }
        } finally {
            $this->reset();
        }
    }

    /**
     * Runs a statement on DuckDB. It is refused while the transaction is
     * rollback-only, and a failure ends a driver-owned rebuild transaction.
     *
     * @param Closure(): T $statement
     *
     * @return T
     *
     * @template T
     */
    public function run(Closure $statement): mixed
    {
        try {
            if ($this->rollbackOnly) {
                throw RollbackOnly::statementRejected();
            }

            try {
                return $statement();
            } catch (DuckDBException $e) {
                throw DriverError::fromDuckDB($e);
            }
        } catch (Throwable $e) {
            $this->abandonDriverOwned();

            throw $e;
        }
    }

    /** Handles a statement the classifier recognised, without sending it to DuckDB. */
    public function intercept(StatementKind $kind, string $sql): void
    {
        try {
            match ($kind) {
                StatementKind::Savepoint,
                StatementKind::ReleaseSavepoint,
                StatementKind::RollbackToSavepoint => $this->savepoint($kind, $sql),
                StatementKind::RebuildBegin => $this->beginRebuild(),
                StatementKind::RebuildEnd => $this->endRebuild(),
                StatementKind::TransactionControl => throw TransactionStatementRejected::literalControl($sql),
                StatementKind::Plain => null,
            };
        } catch (Throwable $e) {
            $this->abandonDriverOwned();

            throw $e;
        }
    }

    private function savepoint(StatementKind $kind, string $sql): void
    {
        if (! $this->active) {
            throw TransactionStatementRejected::savepointOutsideTransaction($sql);
        }

        // DBAL raises its nesting level before SAVEPOINT and lowers it only after
        // ROLLBACK TO SAVEPOINT returns, so those two must not throw here.
        if ($this->rollbackOnly && $kind === StatementKind::ReleaseSavepoint) {
            throw RollbackOnly::statementRejected();
        }

        if ($kind !== StatementKind::RollbackToSavepoint) {
            return;
        }

        $this->rollbackOnly = true;
    }

    private function beginRebuild(): void
    {
        if ($this->active) {
            return;
        }

        $this->begin();
        $this->driverOwned = true;
    }

    private function endRebuild(): void
    {
        if ($this->driverOwned) {
            $this->commit();

            return;
        }

        if (! $this->active) {
            throw TransactionStatementRejected::rebuildEndOutsideRebuild();
        }
    }

    private function abandonDriverOwned(): void
    {
        if (! $this->driverOwned) {
            return;
        }

        $this->rollBackIfOpen();
        $this->reset();
    }

    /** Rolls back best-effort: the caller is already reporting the failure that matters. */
    private function rollBackIfOpen(): void
    {
        try {
            $this->connection->rollBack();
        } catch (DuckDBException) {
        }
    }

    private function reset(): void
    {
        $this->active       = false;
        $this->rollbackOnly = false;
        $this->driverOwned  = false;
    }

    private static function isNoActiveTransaction(DuckDBException $exception): bool
    {
        return str_contains($exception->getMessage(), 'no transaction is active');
    }
}
