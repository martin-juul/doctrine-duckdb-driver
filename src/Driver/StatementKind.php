<?php

declare(strict_types=1);

namespace MartinJuul\DBAL\DuckDB\Driver;

/** What the driver connection does with a statement before anything reaches DuckDB. */
enum StatementKind
{
    /** Runs on DuckDB. */
    case Plain;

    /** `SAVEPOINT <name>`: a nested begin, flattened into the open transaction. */
    case Savepoint;

    /** `RELEASE SAVEPOINT <name>`: a nested commit. */
    case ReleaseSavepoint;

    /** `ROLLBACK TO SAVEPOINT <name>`: a nested rollback, which makes the transaction rollback-only. */
    case RollbackToSavepoint;

    /** The statement that opens a table rebuild. */
    case RebuildBegin;

    /** The statement that closes a table rebuild. */
    case RebuildEnd;

    /** A literal `BEGIN`, `COMMIT`, `ROLLBACK` or equivalent, which the driver rejects. */
    case TransactionControl;
}
