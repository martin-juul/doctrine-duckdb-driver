<?php

declare(strict_types=1);

namespace MartinJuul\DBAL\DuckDB\Driver;

use function preg_match;
use function preg_replace;
use function rtrim;
use function str_contains;
use function substr;

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

    /**
     * First characters that no statement the driver handles starts with: an
     * ASCII letter other than the initials of SAVEPOINT, RELEASE, ROLLBACK,
     * BEGIN, START, COMMIT, END, ABORT and DBAL_*.
     */
    private const string PLAIN_INITIALS = 'FGHIJKLMNOPQTUVWXYZfghijklmnopqtuvwxyz';

    /** First characters that no leading comment or whitespace starts with. */
    private const string LETTERS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

    private const string IDENTIFIER = '(?:[A-Za-z_][A-Za-z0-9_$]*|"(?:[^"]|"")+")';

    private const string LEADING_COMMENTS = '~\A(?:\s+|--[^\n]*(?:\n|\z)|/\*.*?\*/)*~s';

    private const string SAVEPOINT = '~\ASAVEPOINT\s+' . self::IDENTIFIER . '\z~i';

    private const string RELEASE_SAVEPOINT = '~\ARELEASE\s+SAVEPOINT\s+' . self::IDENTIFIER . '\z~i';

    private const string ROLLBACK_TO_SAVEPOINT = '~\AROLLBACK\s+TO\s+SAVEPOINT\s+' . self::IDENTIFIER . '\z~i';

    private const string TRANSACTION_CONTROL = '~\A(?:BEGIN|START\s+TRANSACTION|COMMIT|END|ROLLBACK|ABORT)\b~i';

    public static function classify(string $sql): StatementKind
    {
        $first = substr($sql, 0, 1);

        if ($first !== '' && str_contains(self::PLAIN_INITIALS, $first)) {
            return StatementKind::Plain;
        }

        if ($first === '' || ! str_contains(self::LETTERS, $first)) {
            $sql = preg_replace(self::LEADING_COMMENTS, '', $sql) ?? $sql;
        }

        $statement = rtrim($sql, " \t\n\r\0\x0B;");

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
