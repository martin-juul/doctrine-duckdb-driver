<?php

declare(strict_types=1);

namespace MartinJuul\DBAL\DuckDB\Tests\Unit\Driver;

use MartinJuul\DBAL\DuckDB\Driver\StatementClassifier;
use MartinJuul\DBAL\DuckDB\Driver\StatementKind;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function chr;
use function ord;
use function range;
use function str_contains;
use function strtolower;
use function strtoupper;

final class StatementClassifierTest extends TestCase
{
    /** @return iterable<string, array{string, StatementKind}> */
    public static function statements(): iterable
    {
        yield 'select' => ['SELECT 1', StatementKind::Plain];
        yield 'select mentioning commit' => ["SELECT 'COMMIT'", StatementKind::Plain];
        yield 'insert' => ['INSERT INTO t VALUES (1)', StatementKind::Plain];
        yield 'empty' => ['', StatementKind::Plain];
        yield 'comment only' => ['-- nothing', StatementKind::Plain];
        yield 'identifier starting like begin' => ['BEGINNING', StatementKind::Plain];
        yield 'set' => ['SET TimeZone = \'UTC\'', StatementKind::Plain];
        yield 'delete' => ['DELETE FROM t', StatementKind::Plain];
        yield 'rebuild begin' => [StatementClassifier::REBUILD_BEGIN, StatementKind::RebuildBegin];
        yield 'rebuild end with semicolon' => [StatementClassifier::REBUILD_END . ';', StatementKind::RebuildEnd];
        yield 'savepoint' => ['SAVEPOINT DOCTRINE_2', StatementKind::Savepoint];
        yield 'quoted savepoint' => ['savepoint "a""b"', StatementKind::Savepoint];
        yield 'release savepoint' => ['RELEASE SAVEPOINT DOCTRINE_2', StatementKind::ReleaseSavepoint];
        yield 'rollback to savepoint' => ['ROLLBACK TO SAVEPOINT DOCTRINE_2', StatementKind::RollbackToSavepoint];
        yield 'begin' => ['BEGIN', StatementKind::TransactionControl];
        yield 'begin transaction' => ['BEGIN TRANSACTION', StatementKind::TransactionControl];
        yield 'start transaction' => ['START TRANSACTION', StatementKind::TransactionControl];
        yield 'commit' => ['COMMIT', StatementKind::TransactionControl];
        yield 'lower-case commit' => ['commit;', StatementKind::TransactionControl];
        yield 'end' => ['END', StatementKind::TransactionControl];
        yield 'rollback' => ['ROLLBACK', StatementKind::TransactionControl];
        yield 'abort' => ['ABORT', StatementKind::TransactionControl];
        yield 'line comment then commit' => ["-- done\nCOMMIT", StatementKind::TransactionControl];
        yield 'block comment then commit' => ['/* done */ COMMIT', StatementKind::TransactionControl];
        yield 'multi-line block comment then rollback' => ["/* a\nb */\nROLLBACK", StatementKind::TransactionControl];
        yield 'leading whitespace then commit' => ["  \n\tCOMMIT", StatementKind::TransactionControl];
        yield 'leading whitespace then savepoint' => ['  SAVEPOINT s1', StatementKind::Savepoint];
        yield 'comment then rebuild marker' => ["-- step\n" . StatementClassifier::REBUILD_BEGIN, StatementKind::RebuildBegin];
    }

    #[DataProvider('statements')]
    public function testClassifiesStatement(string $sql, StatementKind $expected): void
    {
        self::assertSame($expected, StatementClassifier::classify($sql));
    }

    /**
     * The classifier returns Plain at once for statements whose first letter
     * starts none of the statements it handles. This pins that every handled
     * keyword still reaches the full check whatever its case.
     */
    public function testEveryHandledKeywordReachesTheFullCheck(): void
    {
        $keywords = ['SAVEPOINT s', 'RELEASE SAVEPOINT s', 'ROLLBACK TO SAVEPOINT s', 'BEGIN', 'START TRANSACTION', 'COMMIT', 'END', 'ROLLBACK', 'ABORT'];

        foreach ($keywords as $keyword) {
            self::assertNotSame(StatementKind::Plain, StatementClassifier::classify($keyword), $keyword);
            self::assertNotSame(StatementKind::Plain, StatementClassifier::classify(strtolower($keyword)), strtolower($keyword));
        }

        self::assertSame(StatementKind::RebuildBegin, StatementClassifier::classify(StatementClassifier::REBUILD_BEGIN));
    }

    /** No statement starting with a letter outside the handled initials is ever treated as transaction control. */
    public function testOtherInitialsAreAlwaysPlain(): void
    {
        foreach (range(ord('A'), ord('Z')) as $code) {
            $letter = chr($code);

            if (str_contains('ABCDERS', $letter)) {
                continue;
            }

            self::assertSame(StatementKind::Plain, StatementClassifier::classify($letter . 'OMMIT'), $letter);
            self::assertSame(StatementKind::Plain, StatementClassifier::classify(strtoupper($letter) . 'ELECT 1'), $letter);
        }
    }
}
