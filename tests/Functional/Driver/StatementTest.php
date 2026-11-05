<?php

declare(strict_types=1);

namespace MartinJuul\DBAL\DuckDB\Tests\Functional\Driver;

use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\ParameterType;
use MartinJuul\DBAL\DuckDB\Tests\Functional\FunctionalTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Stringable;

use function fopen;
use function fwrite;
use function rewind;

final class StatementTest extends FunctionalTestCase
{
    /** @return iterable<string, array{string, mixed, ParameterType, mixed}> */
    public static function bindings(): iterable
    {
        yield 'null' => ['VARCHAR', null, ParameterType::NULL, null];
        yield 'integer' => ['BIGINT', 42, ParameterType::INTEGER, 42];
        yield 'numeric string as integer' => ['BIGINT', '42', ParameterType::INTEGER, 42];
        yield 'string' => ['VARCHAR', 'hello', ParameterType::STRING, 'hello'];
        yield 'ascii' => ['VARCHAR', 'abc', ParameterType::ASCII, 'abc'];
        yield 'boolean' => ['BOOLEAN', true, ParameterType::BOOLEAN, true];
        yield 'binary' => ['BLOB', "\x00\xff\x10", ParameterType::BINARY, "\x00\xff\x10"];
        yield 'large object' => ['BLOB', "\xde\xad\xbe\xef", ParameterType::LARGE_OBJECT, "\xde\xad\xbe\xef"];
    }

    #[DataProvider('bindings')]
    public function testBindsEachParameterType(string $column, mixed $value, ParameterType $type, mixed $expected): void
    {
        $connection = self::connect();
        $connection->executeStatement('CREATE TABLE t (v ' . $column . ')');
        $connection->executeStatement('INSERT INTO t VALUES (?)', [$value], [$type]);

        self::assertSame($expected, $connection->fetchOne('SELECT v FROM t'));
    }

    public function testLargeObjectStreamBindsItsContents(): void
    {
        $stream = fopen('php://memory', 'r+');
        self::assertNotFalse($stream);
        fwrite($stream, "\x01\x02\x03");
        rewind($stream);

        $connection = self::connect();
        $connection->executeStatement('CREATE TABLE t (v BLOB)');
        $connection->executeStatement('INSERT INTO t VALUES (?)', [$stream], [ParameterType::LARGE_OBJECT]);

        self::assertSame("\x01\x02\x03", $connection->fetchOne('SELECT v FROM t'));
    }

    public function testInvalidUtf8StringIsRejectedAndTheSameBytesBindAsBinary(): void
    {
        $connection = self::connect();
        $connection->executeStatement('CREATE TABLE t (v BLOB)');

        try {
            $connection->executeStatement('INSERT INTO t VALUES (?)', ["a\xff"], [ParameterType::STRING]);
            self::fail('An invalid UTF-8 string parameter must be rejected.');
        } catch (DriverException $e) {
            self::assertStringContainsString('not valid UTF-8', $e->getMessage());
        }

        $connection->executeStatement('INSERT INTO t VALUES (?)', ["a\xff"], [ParameterType::BINARY]);

        self::assertSame("a\xff", $connection->fetchOne('SELECT v FROM t'));
    }

    /** @return iterable<string, array{ParameterType}> */
    public static function stringParameterTypes(): iterable
    {
        yield 'string' => [ParameterType::STRING];
        yield 'ascii' => [ParameterType::ASCII];
    }

    #[DataProvider('stringParameterTypes')]
    public function testStringableObjectBindsAsItsString(ParameterType $type): void
    {
        $id = new class ('id-42') implements Stringable {
            public function __construct(private readonly string $value)
            {
            }

            public function __toString(): string
            {
                return $this->value;
            }
        };

        $connection = self::connect();
        $connection->executeStatement('CREATE TABLE t (v VARCHAR)');
        $connection->executeStatement("INSERT INTO t VALUES ('id-42'), ('id-7')");

        self::assertSame(['id-42'], $connection->fetchFirstColumn('SELECT v FROM t WHERE v = ?', [$id], [$type]));
    }

    public function testStringableObjectWithInvalidUtf8IsRejected(): void
    {
        $id = new class implements Stringable {
            public function __toString(): string
            {
                return "a\xff";
            }
        };

        $this->expectException(DriverException::class);
        $this->expectExceptionMessage('not valid UTF-8');

        self::connect()->fetchOne('SELECT ?', [$id], [ParameterType::STRING]);
    }

    public function testStatementRunsAgainWithNewValues(): void
    {
        $connection = self::connect();
        $connection->executeStatement('CREATE TABLE t (v INTEGER)');

        $statement = $connection->prepare('INSERT INTO t VALUES (?)');
        $statement->bindValue(1, 1, ParameterType::INTEGER);
        $statement->executeStatement();
        $statement->bindValue(1, 2, ParameterType::INTEGER);
        $statement->executeStatement();

        self::assertSame([1, 2], $connection->fetchFirstColumn('SELECT v FROM t ORDER BY v'));
    }

    public function testNamedParametersBind(): void
    {
        self::assertSame(
            'b',
            self::connect()->fetchOne('SELECT :second', ['first' => 'a', 'second' => 'b']),
        );
    }

    public function testExecuteStatementReportsAffectedRows(): void
    {
        $connection = self::connect();
        $connection->executeStatement('CREATE TABLE t (v INTEGER)');

        self::assertSame(3, $connection->executeStatement('INSERT INTO t VALUES (1), (2), (3)'));
        self::assertSame(2, $connection->executeStatement('UPDATE t SET v = v + 10 WHERE v > ?', [1]));
        self::assertSame(1, $connection->executeStatement('DELETE FROM t WHERE v = 1'));
    }
}
