<?php

declare(strict_types=1);

namespace MartinJuul\DBAL\DuckDB\Tests\Functional\Driver;

use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\ServerVersionProvider;
use DuckDB\Connection as NativeConnection;
use MartinJuul\DBAL\DuckDB\Driver;
use MartinJuul\DBAL\DuckDB\Platform\DuckDBPlatform;
use MartinJuul\DBAL\DuckDB\Tests\Functional\FunctionalTestCase;
use RuntimeException;

final class ConnectionTest extends FunctionalTestCase
{
    public function testConnectsInMemory(): void
    {
        self::assertSame(42, self::connect(['memory' => true])->fetchOne('SELECT 42'));
    }

    public function testNoFileParameterMeansInMemory(): void
    {
        self::assertSame(1, self::connect()->fetchOne('SELECT 1'));
    }

    public function testFileDatabasePersistsAcrossConnections(): void
    {
        $path = $this->temporaryDatabasePath();

        $writer = self::connect(['dbname' => $path]);
        $writer->executeStatement('CREATE TABLE t (id INTEGER)');
        $writer->executeStatement('INSERT INTO t VALUES (1), (2)');
        $writer->close();

        self::assertSame(2, self::connect(['path' => $path])->fetchOne('SELECT count(*) FROM t'));
    }

    public function testReadOnlyConnectionRefusesWrites(): void
    {
        $path   = $this->temporaryDatabasePath();
        $writer = self::connect(['dbname' => $path]);
        $writer->executeStatement('CREATE TABLE t (id INTEGER)');
        $writer->close();
        unset($writer);

        $reader = self::connect(['dbname' => $path, 'driverOptions' => ['access_mode' => 'read_only']]);

        self::assertSame(0, $reader->fetchOne('SELECT count(*) FROM t'));

        $this->expectException(DriverException::class);
        $reader->executeStatement('INSERT INTO t VALUES (1)');
    }

    public function testUnknownDriverOptionFailsAtConnect(): void
    {
        $this->expectException(DriverException::class);

        self::connect(['driverOptions' => ['no_such_option' => 1]])->fetchOne('SELECT 1');
    }

    public function testPlatformIsReturnedWithoutConnecting(): void
    {
        $neverAsked = new class implements ServerVersionProvider {
            public function getServerVersion(): string
            {
                throw new RuntimeException('The platform must not ask for the server version.');
            }
        };

        self::assertInstanceOf(DuckDBPlatform::class, (new Driver())->getDatabasePlatform($neverAsked));
    }

    public function testLastInsertIdPointsToReturning(): void
    {
        $this->expectException(DriverException::class);
        $this->expectExceptionMessage('RETURNING');

        self::connect()->lastInsertId();
    }

    public function testServerVersionIsTheLinkedDuckDB(): void
    {
        self::assertSame('v2.0.0', self::connect()->getServerVersion());
    }

    public function testQuoteDoublesSingleQuotes(): void
    {
        $connection = self::connect();

        self::assertSame("it's", $connection->fetchOne('SELECT ' . $connection->quote("it's")));
    }

    public function testNativeConnectionIsUsableForAnAppender(): void
    {
        $connection = self::connect();
        $connection->executeStatement('CREATE TABLE t (id INTEGER, name VARCHAR)');

        $native = $connection->getNativeConnection();
        self::assertInstanceOf(NativeConnection::class, $native);

        $appender = $native->appender('t');
        $appender->appendRow([1, 'a']);
        $appender->close();

        self::assertSame([['id' => 1, 'name' => 'a']], $connection->fetchAllAssociative('SELECT * FROM t'));
    }
}
