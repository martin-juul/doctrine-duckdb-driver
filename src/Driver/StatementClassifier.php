<?php

declare(strict_types=1);

namespace MartinJuul\DBAL\DuckDB\Driver;

use function preg_match;
use function preg_replace;
use function rtrim;

/**
 * Recognises the statements the driver connection handles itself, in exec(),
 * query() and prepare() alike.
 *
 * Savepoint statements carry DBAL's nested transactions, which the driver
 * flattens. Rebuild markers bracket a table rebuild. Both stay statements that
 * DuckDB cannot run, so SQL that bypasses the driver fails loudly instead of
 * silently losing its transaction semantics. Literal transaction control is
 * recognised so it can be rejected: a raw COMMIT on an aborted DuckDB
 * transaction reports success while rolling back.
 */
final class StatementClassifier
{
    /**
     * Opens a table rebuild. A bare identifier rather than an SQL comment, so
     * DuckDB rejects it with a parser error when it bypasses the driver.
     */
    public const string REBUILD_BEGIN = 'DBAL_DUCKDB_REBUILD_BEGIN';

    /** Closes a table rebuild; see REBUILD_BEGIN. */
    public const string REBUILD_END = 'DBAL_DUCKDB_REBUILD_END';

    private const string IDENTIFIER = '(?:[A-Za-z_][A-Za-z0-9_$]*|"(?:[^"]|"")+")';

    private const string LEADING_COMMENTS = '~\A(?:\s+|--[^\n]*(?:\n|\z)|/\*.*?\*/)*~s';

    private const string SAVEPOINT = '~\ASAVEPOINT\s+' . self::IDENTIFIER . '\z~i';

    private const string RELEASE_SAVEPOINT = '~\ARELEASE\s+SAVEPOINT\s+' . self::IDENTIFIER . '\z~i';

    private const string ROLLBACK_TO_SAVEPOINT = '~\AROLLBACK\s+TO\s+SAVEPOINT\s+' . self::IDENTIFIER . '\z~i';

    private const string TRANSACTION_CONTROL = '~\A(?:BEGIN|START\s+TRANSACTION|COMMIT|END|ROLLBACK|ABORT)\b~i';

    public static function classify(string $sql): StatementKind
    {
        $statement = rtrim(preg_replace(self::LEADING_COMMENTS, '', $sql) ?? $sql, " \t\n\r\0\x0B;");

        return match (true) {
            $statement === self::REBUILD_BEGIN => StatementKind::RebuildBegin,
            $statement === self::REBUILD_END => StatementKind::RebuildEnd,
            preg_match(self::SAVEPOINT, $statement) === 1 => StatementKind::Savepoint,
            preg_match(self::RELEASE_SAVEPOINT, $statement) === 1 => StatementKind::ReleaseSavepoint,
            preg_match(self::ROLLBACK_TO_SAVEPOINT, $statement) === 1 => StatementKind::RollbackToSavepoint,
            preg_match(self::TRANSACTION_CONTROL, $statement) === 1 => StatementKind::TransactionControl,
            default => StatementKind::Plain,
        };
    }
}
