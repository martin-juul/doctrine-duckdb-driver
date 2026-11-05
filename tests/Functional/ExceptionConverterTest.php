<?php

declare(strict_types=1);

namespace MartinJuul\DBAL\DuckDB\Tests\Functional;

use Doctrine\DBAL\Connection;
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
use Doctrine\DBAL\Exception\RetryableException;
use Doctrine\DBAL\Exception\SchemaDoesNotExist;
use Doctrine\DBAL\Exception\ServerException;
use Doctrine\DBAL\Exception\SyntaxErrorException;
use Doctrine\DBAL\Exception\TableExistsException;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use MartinJuul\DBAL\DuckDB\Exception\EarlyForeignKeyCheckViolation;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\DataProvider;

use function fclose;
use function fgets;
use function proc_close;
use function proc_open;
use function stream_get_contents;
use function stream_select;

use const PHP_BINARY;

final class ExceptionConverterTest extends FunctionalTestCase
{
    /** Seconds a lock-holding helper process may take to start, generous for slow CI and Valgrind. */
    private const int HOLDER_START_TIMEOUT = 60;

    /** @var list<array{resource, array<int, resource>}> each helper process with its pipes */
    private array $holders = [];

    public function testDuplicatePrimaryKeyIsUniqueViolation(): void
    {
        $connection = self::connectWithParentTable();
        $connection->executeStatement('INSERT INTO parent (id, code) VALUES (1, 10)');

        self::assertConverted(
            UniqueConstraintViolationException::class,
            static fn () => $connection->executeStatement('INSERT INTO parent (id, code) VALUES (1, 20)'),
        );
    }

    public function testDuplicateUniqueValueIsUniqueViolation(): void
    {
        $connection = self::connectWithParentTable();
        $connection->executeStatement('INSERT INTO parent (id, code) VALUES (1, 10)');

        self::assertConverted(
            UniqueConstraintViolationException::class,
            static fn () => $connection->executeStatement('INSERT INTO parent (id, code) VALUES (2, 10)'),
        );
    }

    public function testDuplicateKeyWithinOneStatementIsUniqueViolation(): void
    {
        $connection = self::connectWithParentTable();

        self::assertConverted(
            UniqueConstraintViolationException::class,
            static fn () => $connection->executeStatement('INSERT INTO parent (id, code) VALUES (1, 10), (1, 20)'),
        );
    }

    public function testCommitTimeUniquenessConflictIsUniqueViolation(): void
    {
        $path   = $this->temporaryDatabasePath();
        $first  = self::connect(['dbname' => $path]);
        $second = self::connect(['dbname' => $path]);

        $first->executeStatement('CREATE TABLE t (id INTEGER PRIMARY KEY)');

        $first->beginTransaction();
        $second->beginTransaction();

        $first->executeStatement('INSERT INTO t VALUES (5)');
        $second->executeStatement('INSERT INTO t VALUES (5)');

        $first->commit();

        self::assertConverted(
            UniqueConstraintViolationException::class,
            static fn () => $second->commit(),
        );
    }

    public function testMissingParentKeyIsForeignKeyViolation(): void
    {
        $connection = self::connectWithParentTable();

        self::assertConverted(
            ForeignKeyConstraintViolationException::class,
            static fn () => $connection->executeStatement('INSERT INTO child (id, parent_id) VALUES (1, 99)'),
        );
    }

    public function testDeletingChildThenParentInOneTransactionIsEarlyCheckViolation(): void
    {
        $connection = self::connectWithParentTable();
        $connection->executeStatement('INSERT INTO parent (id, code) VALUES (1, 10)');
        $connection->executeStatement('INSERT INTO child (id, parent_id) VALUES (1, 1)');

        $connection->beginTransaction();
        $connection->executeStatement('DELETE FROM child WHERE id = 1');

        $exception = self::assertConverted(
            EarlyForeignKeyCheckViolation::class,
            static fn () => $connection->executeStatement('DELETE FROM parent WHERE id = 1'),
        );

        self::assertInstanceOf(ForeignKeyConstraintViolationException::class, $exception);
        self::assertStringContainsString('still referenced by a foreign key', $exception->getMessage());
        self::assertStringContainsString('early foreign-key check', $exception->getMessage());
        self::assertStringContainsString('Over-eager constraint checking in foreign keys', $exception->getMessage());
    }

    public function testUpdatingUniqueColumnOfReferencedRowIsEarlyCheckViolation(): void
    {
        $connection = self::connectWithParentTable();
        $connection->executeStatement('INSERT INTO parent (id, code) VALUES (1, 10)');
        $connection->executeStatement('INSERT INTO child (id, parent_id) VALUES (1, 1)');

        self::assertConverted(
            EarlyForeignKeyCheckViolation::class,
            static fn () => $connection->executeStatement('UPDATE parent SET code = 11 WHERE id = 1'),
        );
    }

    public function testNullInNotNullColumnIsNotNullViolation(): void
    {
        $connection = self::connectWithParentTable();

        self::assertConverted(
            NotNullConstraintViolationException::class,
            static fn () => $connection->executeStatement('INSERT INTO parent (id, code, name) VALUES (1, 10, NULL)'),
        );
    }

    public function testCheckViolationIsGenericConstraintViolation(): void
    {
        $connection = self::connectWithParentTable();

        self::assertConverted(
            ConstraintViolationException::class,
            static fn () => $connection->executeStatement('INSERT INTO parent (id, code, amount) VALUES (1, 10, -1)'),
        );
    }

    /** @return iterable<string, array{string}> */
    public static function missingObjects(): iterable
    {
        yield 'table' => ['SELECT * FROM missing'];
        yield 'view' => ['DROP VIEW missing'];
        yield 'sequence' => ['DROP SEQUENCE missing'];
        yield 'index' => ['DROP INDEX missing'];
        yield 'table in a missing schema' => ['SELECT * FROM missing_schema.missing'];
    }

    #[DataProvider('missingObjects')]
    public function testMissingObjectIsTableNotFound(string $sql): void
    {
        $connection = self::connect();

        self::assertConverted(
            TableNotFoundException::class,
            static fn () => $connection->executeStatement($sql),
        );
    }

    /** @return iterable<string, array{string}> */
    public static function missingSchemas(): iterable
    {
        yield 'drop' => ['DROP SCHEMA missing_schema'];
        yield 'create table in it' => ['CREATE TABLE missing_schema.t (id INTEGER)'];
    }

    #[DataProvider('missingSchemas')]
    public function testMissingSchemaIsSchemaDoesNotExist(string $sql): void
    {
        $connection = self::connect();

        $exception = self::assertConverted(
            SchemaDoesNotExist::class,
            static fn () => $connection->executeStatement($sql),
        );

        self::assertInstanceOf(DatabaseObjectNotFoundException::class, $exception);
    }

    /** @return iterable<string, array{string}> */
    public static function missingOtherObjects(): iterable
    {
        yield 'type' => ['DROP TYPE missing'];
        yield 'macro' => ['DROP MACRO missing'];
        yield 'scalar function' => ['SELECT missing(1)'];
    }

    #[DataProvider('missingOtherObjects')]
    public function testOtherMissingObjectIsDatabaseObjectNotFound(string $sql): void
    {
        $connection = self::connect();

        self::assertConverted(
            DatabaseObjectNotFoundException::class,
            static fn () => $connection->executeStatement($sql),
        );
    }

    /** @return iterable<string, array{string}> */
    public static function existingObjects(): iterable
    {
        yield 'table' => ['CREATE TABLE t (id INTEGER)'];
        yield 'view' => ['CREATE VIEW v AS SELECT 1'];
        yield 'sequence' => ['CREATE SEQUENCE s'];
        yield 'index' => ['CREATE INDEX i ON t (id)'];
    }

    #[DataProvider('existingObjects')]
    public function testDuplicateObjectIsTableExists(string $sql): void
    {
        $connection = self::connect();
        $connection->executeStatement('CREATE TABLE t (id INTEGER)');
        $connection->executeStatement('CREATE VIEW v AS SELECT 1');
        $connection->executeStatement('CREATE SEQUENCE s');
        $connection->executeStatement('CREATE INDEX i ON t (id)');

        self::assertConverted(
            TableExistsException::class,
            static fn () => $connection->executeStatement($sql),
        );
    }

    /** @return iterable<string, array{string}> */
    public static function existingOtherObjects(): iterable
    {
        yield 'schema' => ['CREATE SCHEMA main'];
        yield 'type' => ['CREATE TYPE ty AS INTEGER'];
    }

    #[DataProvider('existingOtherObjects')]
    public function testOtherDuplicateObjectIsDatabaseObjectExists(string $sql): void
    {
        $connection = self::connect();
        $connection->executeStatement('CREATE TYPE ty AS INTEGER');

        self::assertConverted(
            DatabaseObjectExistsException::class,
            static fn () => $connection->executeStatement($sql),
        );
    }

    public function testDroppingObjectWithDependentsIsServerException(): void
    {
        $connection = self::connect();
        $connection->executeStatement('CREATE SEQUENCE s');
        $connection->executeStatement("CREATE TABLE t (id INTEGER DEFAULT nextval('s'))");

        self::assertConverted(
            ServerException::class,
            static fn () => $connection->executeStatement('DROP SEQUENCE s'),
        );
    }

    public function testSyntaxErrorIsSyntaxErrorException(): void
    {
        $connection = self::connect();

        self::assertConverted(
            SyntaxErrorException::class,
            static fn () => $connection->executeQuery('SELEC 1'),
        );
    }

    /** @return iterable<string, array{string}> */
    public static function unknownColumns(): iterable
    {
        yield 'select' => ['SELECT missing FROM parent'];
        yield 'insert' => ['INSERT INTO parent (missing) VALUES (1)'];
    }

    #[DataProvider('unknownColumns')]
    public function testUnknownColumnIsInvalidFieldName(string $sql): void
    {
        $connection = self::connectWithParentTable();

        self::assertConverted(
            InvalidFieldNameException::class,
            static fn () => $connection->executeStatement($sql),
        );
    }

    public function testAmbiguousColumnIsNonUniqueFieldName(): void
    {
        $connection = self::connectWithParentTable();

        self::assertConverted(
            NonUniqueFieldNameException::class,
            static fn () => $connection->executeQuery('SELECT id FROM parent, child'),
        );
    }

    /** @return iterable<string, array{string, string}> */
    public static function writeWriteConflicts(): iterable
    {
        yield 'update after update' => [
            'UPDATE parent SET code = 11 WHERE id = 1',
            'UPDATE parent SET code = 12 WHERE id = 1',
        ];

        yield 'delete after delete' => [
            'DELETE FROM parent WHERE id = 1',
            'DELETE FROM parent WHERE id = 1',
        ];

        yield 'catalog change after catalog change' => [
            'CREATE TABLE created (id INTEGER)',
            'CREATE TABLE created (id INTEGER)',
        ];
    }

    #[DataProvider('writeWriteConflicts')]
    public function testStatementTimeWriteWriteConflictIsDeadlock(string $firstSql, string $secondSql): void
    {
        $path   = $this->temporaryDatabasePath();
        $first  = self::connect(['dbname' => $path]);
        $second = self::connect(['dbname' => $path]);

        self::createParentTable($first);
        $first->executeStatement('INSERT INTO parent (id, code) VALUES (1, 10)');

        $first->beginTransaction();
        $second->beginTransaction();

        $first->executeStatement($firstSql);

        $exception = self::assertConverted(
            DeadlockException::class,
            static fn () => $second->executeStatement($secondSql),
        );

        self::assertInstanceOf(RetryableException::class, $exception);

        $first->rollBack();
        $second->rollBack();
    }

    public function testWriteToReadOnlyDatabaseIsDriverException(): void
    {
        $path   = $this->temporaryDatabasePath();
        $writer = self::connect(['dbname' => $path]);
        $writer->executeStatement('CREATE TABLE t (id INTEGER)');
        $writer->close();
        unset($writer);

        $reader = self::connect(['dbname' => $path, 'driverOptions' => ['access_mode' => 'read_only']]);

        self::assertConverted(
            DriverException::class,
            static fn () => $reader->executeStatement('INSERT INTO t VALUES (1)'),
        );
    }

    /** @return iterable<string, array{array<string, string>}> */
    public static function openModes(): iterable
    {
        yield 'read-write' => [[]];
        yield 'read-only' => [['access_mode' => 'read_only']];
    }

    /** @param array<string, string> $driverOptions */
    #[DataProvider('openModes')]
    public function testOpeningFileHeldForWritingByAnotherProcessIsConnectionException(array $driverOptions): void
    {
        $path = $this->createDatabaseFile();

        $this->holdInAnotherProcess($path, 'read_write');

        $connection = self::connect(['dbname' => $path, 'driverOptions' => $driverOptions]);

        $exception = self::assertConverted(
            ConnectionException::class,
            static fn () => $connection->fetchOne('SELECT 1'),
        );

        self::assertStringContainsString('Conflicting lock is held', $exception->getMessage());
        self::assertStringContainsString(
            'one process open it for writing or any number of processes open it read-only',
            $exception->getMessage(),
        );
    }

    public function testReadOnlyHoldersAdmitReadersAndRefuseWriters(): void
    {
        $path = $this->createDatabaseFile();

        $this->holdInAnotherProcess($path, 'read_only');

        $writer = self::connect(['dbname' => $path]);

        $exception = self::assertConverted(
            ConnectionException::class,
            static fn () => $writer->fetchOne('SELECT 1'),
        );

        self::assertStringContainsString(
            'one process open it for writing or any number of processes open it read-only',
            $exception->getMessage(),
        );

        $reader = self::connect(['dbname' => $path, 'driverOptions' => ['access_mode' => 'read_only']]);

        self::assertSame(0, $reader->fetchOne('SELECT count(*) FROM t'));
    }

    public function testOpeningSameFileWithDifferentConfigurationIsConnectionException(): void
    {
        $path  = $this->createDatabaseFile();
        $first = self::connect(['dbname' => $path]);
        $first->fetchOne('SELECT 1');

        $second = self::connect(['dbname' => $path, 'driverOptions' => ['access_mode' => 'read_only']]);

        $exception = self::assertConverted(
            ConnectionException::class,
            static fn () => $second->fetchOne('SELECT 1'),
        );

        self::assertStringContainsString('different configuration', $exception->getMessage());
        self::assertStringContainsString('same driverOptions', $exception->getMessage());
    }

    #[After]
    protected function releaseHolders(): void
    {
        foreach ($this->holders as [$process, $pipes]) {
            fclose($pipes[0]);

            stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            fclose($pipes[2]);

            proc_close($process);
        }

        $this->holders = [];
    }

    /**
     * Runs the operation and asserts DBAL converted its error to exactly the given class.
     *
     * @param class-string<T>   $expected
     * @param callable(): mixed $operation
     *
     * @return T
     *
     * @template T of DriverException
     */
    private static function assertConverted(string $expected, callable $operation): DriverException
    {
        try {
            $operation();
        } catch (DriverException $exception) {
            self::assertSame($expected, $exception::class, $exception->getMessage());
            self::assertInstanceOf($expected, $exception);

            return $exception;
        }

        self::fail('Expected ' . $expected . ', but no exception was thrown.');
    }

    private static function connectWithParentTable(): Connection
    {
        $connection = self::connect();

        self::createParentTable($connection);

        return $connection;
    }

    private static function createParentTable(Connection $connection): void
    {
        $connection->executeStatement(
            'CREATE TABLE parent ('
            . 'id INTEGER PRIMARY KEY, '
            . 'code INTEGER UNIQUE, '
            . "name VARCHAR NOT NULL DEFAULT '', "
            . 'amount INTEGER CHECK (amount > 0))',
        );

        $connection->executeStatement(
            'CREATE TABLE child (id INTEGER PRIMARY KEY, parent_id INTEGER REFERENCES parent (id))',
        );
    }

    /** Creates a database file with an empty table t and releases it. */
    private function createDatabaseFile(): string
    {
        $path       = $this->temporaryDatabasePath();
        $connection = self::connect(['dbname' => $path]);
        $connection->executeStatement('CREATE TABLE t (id INTEGER)');
        $connection->close();

        return $path;
    }

    /** Opens the file in a helper process that keeps it open until the test ends. */
    private function holdInAnotherProcess(string $path, string $mode): void
    {
        $pipes   = [];
        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/Fixtures/hold-lock.php', $path, $mode],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );

        self::assertIsResource($process);

        $this->holders[] = [$process, $pipes];

        $read   = [$pipes[1]];
        $write  = null;
        $except = null;

        $ready = stream_select($read, $write, $except, self::HOLDER_START_TIMEOUT);

        self::assertSame(1, $ready, 'The lock-holding process did not start in time.');

        if (fgets($pipes[1]) === "ready\n") {
            return;
        }

        self::fail('The lock-holding process failed: ' . stream_get_contents($pipes[2]));
    }
}
