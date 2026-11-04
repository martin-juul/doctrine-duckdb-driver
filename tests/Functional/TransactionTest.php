<?php

declare(strict_types=1);

namespace MartinJuul\DBAL\DuckDB\Tests\Functional;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\TransactionRolledBack;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use DuckDB\Exception;
use MartinJuul\DBAL\DuckDB\Driver;
use MartinJuul\DBAL\DuckDB\Driver\StatementClassifier;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Throwable;

use function sprintf;
use function str_contains;

final class TransactionTest extends FunctionalTestCase
{
    /** AE4: a nested level that commits commits nothing until the outer level commits. */
    public function testNestedCommitWaitsForOuterCommit(): void
    {
        [$connection, $observer] = $this->connectPair();

        $connection->beginTransaction();
        $connection->beginTransaction();
        $connection->executeStatement('INSERT INTO t VALUES (1)');
        $connection->commit();

        self::assertSame(1, $connection->getTransactionNestingLevel());
        self::assertSame(0, $observer->fetchOne('SELECT count(*) FROM t'));

        $connection->commit();

        self::assertSame(0, $connection->getTransactionNestingLevel());
        self::assertSame(1, $observer->fetchOne('SELECT count(*) FROM t'));
    }

    /** AE11: after an inner rollback every statement fails and the outer commit rolls everything back. */
    public function testInnerRollbackMakesTheTransactionRollbackOnly(): void
    {
        [$connection, $observer] = $this->connectPair();

        $connection->beginTransaction();
        $connection->executeStatement('INSERT INTO t VALUES (1)');
        $connection->beginTransaction();
        $connection->executeStatement('INSERT INTO t VALUES (2)');
        $connection->rollBack();

        try {
            $connection->fetchOne('SELECT count(*) FROM t');
            self::fail('A SELECT after an inner rollback must fail.');
        } catch (TransactionRolledBack $e) {
            self::assertStringContainsString('rollback-only', $e->getMessage());
        }

        try {
            $connection->commit();
            self::fail('The outer commit after an inner rollback must fail.');
        } catch (TransactionRolledBack $e) {
            self::assertStringContainsString('nothing was committed', $e->getMessage());
        }

        self::assertSame(0, $connection->getTransactionNestingLevel());
        self::assertFalse($connection->isTransactionActive());
        self::assertSame(0, $observer->fetchOne('SELECT count(*) FROM t'));

        $connection->executeStatement('INSERT INTO t VALUES (3)');

        self::assertSame([3], $observer->fetchFirstColumn('SELECT id FROM t'));
    }

    public function testReleasingASavepointAfterADeeperRollbackFails(): void
    {
        $connection = self::connect();
        $connection->executeStatement('CREATE TABLE t (id INTEGER)');

        $connection->beginTransaction();
        $connection->beginTransaction();
        $connection->beginTransaction();
        $connection->rollBack();

        try {
            $connection->commit();
            self::fail('Committing a level above a rolled-back level must fail.');
        } catch (TransactionRolledBack $e) {
            self::assertStringContainsString('rollback-only', $e->getMessage());
        }

        self::assertSame(1, $connection->getTransactionNestingLevel());

        $connection->rollBack();

        self::assertSame(0, $connection->getTransactionNestingLevel());
        self::assertSame(0, $connection->fetchOne('SELECT count(*) FROM t'));
    }

    /** AE2: a statement failed, so commit rolls back and the connection is idle and usable. */
    public function testCommitAfterAFailedStatementRollsBack(): void
    {
        $connection = self::connect();
        $connection->executeStatement('CREATE TABLE t (id INTEGER PRIMARY KEY)');

        $connection->beginTransaction();
        $connection->executeStatement('INSERT INTO t VALUES (1)');

        try {
            $connection->executeStatement('INSERT INTO t VALUES (1)');
            self::fail('The duplicate insert must fail.');
        } catch (UniqueConstraintViolationException) {
        }

        try {
            $connection->commit();
            self::fail('Committing an aborted transaction must fail.');
        } catch (TransactionRolledBack $e) {
            self::assertChainContains('Transaction was aborted, so nothing was committed', $e);
        }

        self::assertFalse($connection->isTransactionActive());
        self::assertSame(0, $connection->fetchOne('SELECT count(*) FROM t'));

        $connection->executeStatement('INSERT INTO t VALUES (2)');

        self::assertSame([2], $connection->fetchFirstColumn('SELECT id FROM t'));
    }

    public function testTransactionalRethrowsTheClosureException(): void
    {
        $connection = self::connect();
        $connection->executeStatement('CREATE TABLE t (id INTEGER)');
        $failure = new RuntimeException('closure failed');

        $caught = null;

        try {
            $connection->transactional(static function (Connection $connection) use ($failure): never {
                $connection->executeStatement('INSERT INTO t VALUES (1)');

                throw $failure;
            });
        } catch (RuntimeException $caught) {
        }

        self::assertSame($failure, $caught);

        self::assertSame(0, $connection->getTransactionNestingLevel());
        self::assertSame(0, $connection->fetchOne('SELECT count(*) FROM t'));
    }

    /**
     * DuckDB fails this commit with "Failed to commit: could not allocate block …" and has
     * already ended the transaction when commit() throws.
     */
    public function testTransactionalRethrowsAnOutOfMemoryCommitFailure(): void
    {
        $connection = self::connect(['dbname' => $this->temporaryDatabasePath()]);
        $connection->executeStatement('CREATE TABLE t (id BIGINT, s VARCHAR)');

        try {
            $connection->transactional(static function (Connection $connection): void {
                $connection->executeStatement(
                    "INSERT INTO t SELECT range, repeat('x', 100) || range FROM range(100000)",
                );
                $connection->executeStatement("SET memory_limit = '1MB'");
            });
            self::fail('The commit must run out of memory.');
        } catch (TransactionRolledBack $e) {
            self::assertChainContains('Failed to commit', $e);
        } finally {
            $connection->executeStatement("SET memory_limit = '1GB'");
        }

        self::assertSame(0, $connection->getTransactionNestingLevel());
        self::assertSame(0, $connection->fetchOne('SELECT count(*) FROM t'));
    }

    public function testCommitTimeUniquenessConflictLeavesBothConnectionsUsable(): void
    {
        $path   = $this->temporaryDatabasePath();
        $first  = self::connect(['dbname' => $path]);
        $second = self::connect(['dbname' => $path]);
        $first->executeStatement('CREATE TABLE t (id INTEGER PRIMARY KEY)');

        $first->beginTransaction();
        $second->beginTransaction();
        $first->executeStatement('INSERT INTO t VALUES (1)');
        $second->executeStatement('INSERT INTO t VALUES (1)');
        $first->commit();

        try {
            $second->commit();
            self::fail('The second commit must conflict.');
        } catch (UniqueConstraintViolationException $e) {
            self::assertStringContainsString('PRIMARY KEY or UNIQUE constraint violation', $e->getMessage());
        }

        self::assertFalse($second->isTransactionActive());

        $second->executeStatement('INSERT INTO t VALUES (2)');
        $first->executeStatement('INSERT INTO t VALUES (3)');

        self::assertSame([1, 2, 3], $first->fetchFirstColumn('SELECT id FROM t ORDER BY id'));
    }

    /** @return iterable<string, array{string}> */
    public static function savepointStatements(): iterable
    {
        yield 'savepoint' => ['SAVEPOINT DOCTRINE_2'];
        yield 'release' => ['RELEASE SAVEPOINT DOCTRINE_2'];
        yield 'rollback to' => ['ROLLBACK TO SAVEPOINT DOCTRINE_2'];
        yield 'lower case, quoted name' => ['savepoint "my point";'];
    }

    #[DataProvider('savepointStatements')]
    public function testSavepointStatementOutsideATransactionFails(string $sql): void
    {
        $connection = self::connect();

        $this->expectException(DriverException::class);
        $this->expectExceptionMessage('only run inside a transaction');

        $connection->executeStatement($sql);
    }

    /** @return iterable<string, array{string}> */
    public static function transactionControlStatements(): iterable
    {
        yield 'BEGIN' => ['BEGIN'];
        yield 'BEGIN TRANSACTION' => ['BEGIN TRANSACTION'];
        yield 'START TRANSACTION' => ['START TRANSACTION'];
        yield 'COMMIT' => ['COMMIT'];
        yield 'commit; lower case' => ['commit;'];
        yield 'END' => ['END'];
        yield 'ROLLBACK' => ['ROLLBACK'];
        yield 'ABORT' => ['ABORT'];
        yield 'after a comment' => ["-- finish\nCOMMIT"];
    }

    #[DataProvider('transactionControlStatements')]
    public function testLiteralTransactionControlIsRejected(string $sql): void
    {
        $connection = self::connect();
        $connection->executeStatement('CREATE TABLE t (id INTEGER)');
        $connection->beginTransaction();
        $connection->executeStatement('INSERT INTO t VALUES (1)');

        try {
            $connection->executeStatement($sql);
            self::fail(sprintf('"%s" must be rejected.', $sql));
        } catch (DriverException $e) {
            self::assertStringContainsString('beginTransaction(), commit() and rollBack()', $e->getMessage());
        }

        self::assertSame(1, $connection->fetchOne('SELECT count(*) FROM t'));

        $connection->rollBack();

        self::assertSame(0, $connection->fetchOne('SELECT count(*) FROM t'));
    }

    /** Three levels, the two inner ones roll back in turn, then the outer one rolls back. */
    public function testNestedRollbacksUnwindToAnIdleConnection(): void
    {
        [$connection, $observer] = $this->connectPair();
        $failure                 = new RuntimeException('innermost failed');

        $caught = null;

        try {
            $connection->transactional(static function (Connection $connection) use ($failure): never {
                $connection->executeStatement('INSERT INTO t VALUES (1)');

                $connection->transactional(static function (Connection $connection) use ($failure): never {
                    $connection->executeStatement('INSERT INTO t VALUES (2)');

                    $connection->transactional(static function (Connection $connection) use ($failure): never {
                        $connection->executeStatement('INSERT INTO t VALUES (3)');

                        throw $failure;
                    });
                });
            });
        } catch (RuntimeException $caught) {
        }

        self::assertSame($failure, $caught);

        self::assertSame(0, $connection->getTransactionNestingLevel());
        self::assertSame(0, $observer->fetchOne('SELECT count(*) FROM t'));

        $connection->executeStatement('INSERT INTO t VALUES (4)');

        self::assertSame([4], $observer->fetchFirstColumn('SELECT id FROM t'));
    }

    public function testRollBackSucceedsWhenDuckDBHoldsNoTransaction(): void
    {
        $connection = self::connect();
        $connection->beginTransaction();
        $native = $connection->getNativeConnection();
        self::assertInstanceOf(\DuckDB\Connection::class, $native);
        $native->rollBack();

        $connection->rollBack();

        self::assertFalse($connection->isTransactionActive());
        self::assertSame(1, $connection->fetchOne('SELECT 1'));
    }

    /** @return iterable<string, array{string}> */
    public static function entryPoints(): iterable
    {
        yield 'executeStatement()' => ['executeStatement'];
        yield 'executeQuery()' => ['executeQuery'];
        yield 'prepare()' => ['prepare'];
    }

    #[DataProvider('entryPoints')]
    public function testSavepointMarkersAreInterceptedOnEveryPath(string $entryPoint): void
    {
        $connection = self::connect();
        $connection->executeStatement('CREATE TABLE t (id INTEGER)');
        $connection->beginTransaction();

        self::runVia($connection, $entryPoint, 'SAVEPOINT DOCTRINE_2');
        self::runVia($connection, $entryPoint, 'RELEASE SAVEPOINT DOCTRINE_2');
        self::runVia($connection, $entryPoint, 'SAVEPOINT DOCTRINE_2');
        self::runVia($connection, $entryPoint, 'ROLLBACK TO SAVEPOINT DOCTRINE_2');

        try {
            self::runVia($connection, $entryPoint, 'SELECT count(*) FROM t');
            self::fail('The transaction must be rollback-only.');
        } catch (TransactionRolledBack $e) {
            self::assertStringContainsString('rollback-only', $e->getMessage());
        }

        $connection->rollBack();

        self::assertSame(0, $connection->fetchOne('SELECT count(*) FROM t'));
    }

    public function testSavepointMarkerQueryReturnsAnEmptyResult(): void
    {
        $connection = self::connect();
        $connection->beginTransaction();

        $result = $connection->executeQuery('SAVEPOINT DOCTRINE_2');

        self::assertSame(0, $result->columnCount());
        self::assertSame(0, $result->rowCount());
        self::assertFalse($result->fetchAssociative());

        $connection->rollBack();
    }

    #[DataProvider('entryPoints')]
    public function testRebuildMarkersCommitTheirOwnTransaction(string $entryPoint): void
    {
        [$connection, $observer] = $this->connectPair();

        self::runVia($connection, $entryPoint, StatementClassifier::REBUILD_BEGIN);
        self::runVia($connection, $entryPoint, 'INSERT INTO t VALUES (1)');

        self::assertSame(0, $observer->fetchOne('SELECT count(*) FROM t'));

        self::runVia($connection, $entryPoint, StatementClassifier::REBUILD_END);

        self::assertSame(1, $observer->fetchOne('SELECT count(*) FROM t'));
        self::assertFalse($connection->isTransactionActive());
    }

    #[DataProvider('entryPoints')]
    public function testRebuildMarkersRollBackOnAnyError(string $entryPoint): void
    {
        [$connection, $observer] = $this->connectPair();

        self::runVia($connection, $entryPoint, StatementClassifier::REBUILD_BEGIN);
        self::runVia($connection, $entryPoint, 'INSERT INTO t VALUES (1)');

        try {
            self::runVia($connection, $entryPoint, 'INSERT INTO missing VALUES (1)');
            self::fail('The insert into a missing table must fail.');
        } catch (DriverException $e) {
            self::assertStringContainsString('missing', $e->getMessage());
        }

        $connection->executeStatement('INSERT INTO t VALUES (2)');

        self::assertSame([2], $observer->fetchFirstColumn('SELECT id FROM t'));
    }

    public function testRebuildMarkersInsideACallerTransactionDoNothing(): void
    {
        $connection = self::connect();
        $connection->executeStatement('CREATE TABLE t (id INTEGER)');

        $connection->beginTransaction();
        $connection->executeStatement(StatementClassifier::REBUILD_BEGIN);
        $connection->executeStatement('INSERT INTO t VALUES (1)');
        $connection->executeStatement(StatementClassifier::REBUILD_END);
        $connection->rollBack();

        self::assertSame(0, $connection->fetchOne('SELECT count(*) FROM t'));
    }

    public function testRebuildMarkerFailsLoudlyOnTheNativeConnection(): void
    {
        $native = self::connect()->getNativeConnection();
        self::assertInstanceOf(\DuckDB\Connection::class, $native);

        $this->expectException(Exception::class);

        $native->query(StatementClassifier::REBUILD_BEGIN);
    }

    public function testConnectionWithoutAutoCommitStartsANewTransactionAfterEachCommit(): void
    {
        $path          = $this->temporaryDatabasePath();
        $configuration = new Configuration();
        $configuration->setAutoCommit(false);
        $connection = DriverManager::getConnection(
            ['driverClass' => Driver::class, 'dbname' => $path],
            $configuration,
        );
        $observer   = self::connect(['dbname' => $path]);
        $observer->executeStatement('CREATE TABLE t (id INTEGER PRIMARY KEY)');

        $connection->executeStatement('INSERT INTO t VALUES (1)');
        $connection->commit();

        self::assertTrue($connection->isTransactionActive());
        self::assertSame(1, $observer->fetchOne('SELECT count(*) FROM t'));

        $connection->executeStatement('INSERT INTO t VALUES (2)');

        try {
            $connection->executeStatement('INSERT INTO t VALUES (1)');
            self::fail('The duplicate insert must fail.');
        } catch (UniqueConstraintViolationException) {
        }

        try {
            $connection->commit();
            self::fail('Committing an aborted transaction must fail.');
        } catch (TransactionRolledBack) {
        }

        self::assertTrue($connection->isTransactionActive());
        self::assertSame(1, $connection->getTransactionNestingLevel());

        $connection->executeStatement('INSERT INTO t VALUES (3)');
        $connection->commit();

        self::assertSame([1, 3], $observer->fetchFirstColumn('SELECT id FROM t ORDER BY id'));
    }

    /**
     * Two connections to one new database file holding an empty table t, sharing one
     * DuckDB instance through ext-duckdb's instance cache.
     *
     * @return array{Connection, Connection}
     */
    private function connectPair(): array
    {
        $path     = $this->temporaryDatabasePath();
        $observer = self::connect(['dbname' => $path]);
        $observer->executeStatement('CREATE TABLE t (id INTEGER)');

        return [self::connect(['dbname' => $path]), $observer];
    }

    private static function runVia(Connection $connection, string $entryPoint, string $sql): void
    {
        match ($entryPoint) {
            'executeStatement' => $connection->executeStatement($sql),
            'executeQuery' => $connection->executeQuery($sql)->fetchAllAssociative(),
            'prepare' => $connection->prepare($sql)->executeQuery()->fetchAllAssociative(),
            default => throw new RuntimeException('Unknown entry point ' . $entryPoint),
        };
    }

    private static function assertChainContains(string $message, Throwable $exception): void
    {
        for ($e = $exception; $e !== null; $e = $e->getPrevious()) {
            if (str_contains($e->getMessage(), $message)) {
                return;
            }
        }

        self::fail(sprintf('No exception in the chain of "%s" contains "%s".', $exception->getMessage(), $message));
    }
}
