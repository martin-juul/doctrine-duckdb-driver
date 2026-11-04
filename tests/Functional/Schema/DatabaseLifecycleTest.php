<?php

declare(strict_types=1);

namespace MartinJuul\DBAL\DuckDB\Tests\Functional\Schema;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\InvalidArgumentException;
use MartinJuul\DBAL\DuckDB\Schema\DuckDBSchemaManager;
use MartinJuul\DBAL\DuckDB\Tests\Functional\FunctionalTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

use function file_get_contents;
use function file_put_contents;

final class DatabaseLifecycleTest extends FunctionalTestCase
{
    public function testCreateDatabaseCreatesAnEmptyDatabaseFile(): void
    {
        $path = $this->temporaryDatabasePath();

        self::schemaManager(self::connect())->createDatabase($path);

        self::assertFileExists($path);

        $created = self::connect(['dbname' => $path]);

        self::assertSame([], self::schemaManager($created)->introspectTableNames());

        $created->executeStatement('CREATE TABLE t (id INTEGER)');

        self::assertSame(0, $created->fetchOne('SELECT count(*) FROM t'));
    }

    public function testCreateDatabaseLeavesAnExistingDatabaseUnchanged(): void
    {
        $path     = $this->temporaryDatabasePath();
        $existing = self::connect(['dbname' => $path]);

        $existing->executeStatement('CREATE TABLE t (id INTEGER)');
        $existing->executeStatement('INSERT INTO t VALUES (1)');
        $existing->close();

        self::schemaManager(self::connect())->createDatabase($path);

        self::assertSame(1, self::connect(['dbname' => $path])->fetchOne('SELECT count(*) FROM t'));
    }

    public function testDropDatabaseDeletesTheFileAndItsWriteAheadLog(): void
    {
        $path = $this->temporaryDatabasePath();

        $schemaManager = self::schemaManager(self::connect());
        $schemaManager->createDatabase($path);

        file_put_contents($path . '.wal', 'pending');

        $schemaManager->dropDatabase($path);

        self::assertFileDoesNotExist($path);
        self::assertFileDoesNotExist($path . '.wal');
    }

    public function testDropDatabaseOfAMissingFileDoesNothing(): void
    {
        $path = $this->temporaryDatabasePath();

        self::schemaManager(self::connect())->dropDatabase($path);

        self::assertFileDoesNotExist($path);
    }

    public function testDropDatabaseRefusesAFileThatIsNotADuckDBDatabase(): void
    {
        $path = $this->temporaryDatabasePath();

        file_put_contents($path, 'SQLite format 3');

        try {
            self::schemaManager(self::connect())->dropDatabase($path);
            self::fail('Dropping a file that is not a DuckDB database succeeded.');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('not a DuckDB database', $e->getMessage());
        }

        self::assertSame('SQLite format 3', file_get_contents($path));
    }

    /**
     * DoctrineBundle's doctrine:database:create removes dbname and path, opens the remaining parameters and passes
     * the quoted dbname; doctrine:database:drop does the same with dbname.
     */
    public function testDoctrineBundleCommandsWorkFromTheFileLessConnectionTheyOpen(): void
    {
        $path       = $this->temporaryDatabasePath();
        $configured = self::connect(['dbname' => $path, 'driverOptions' => ['threads' => 1]]);

        $params = $configured->getParams();
        unset($params['dbname'], $params['path']);

        $temporary = DriverManager::getConnection($params, $configured->getConfiguration());
        $name      = $temporary->getDatabasePlatform()->quoteSingleIdentifier($path);

        self::assertSame([], self::schemaManager($temporary)->listDatabases());

        self::schemaManager($temporary)->createDatabase($name);

        self::assertFileExists($path);

        $configured->executeStatement('CREATE TABLE t (id INTEGER)');
        $configured->close();

        self::schemaManager($temporary)->dropDatabase($name);

        self::assertFileDoesNotExist($path);
    }

    /** doctrine:database:drop keeps a "path" parameter, so its connection has the file open while it drops it. */
    public function testDropDatabaseWorksFromAConnectionThatHasTheFileOpen(): void
    {
        $path       = $this->temporaryDatabasePath();
        $connection = self::connect(['path' => $path]);

        $connection->executeStatement('CREATE TABLE t (id INTEGER)');

        $schemaManager = self::schemaManager($connection);

        self::assertSame([$path], $schemaManager->listDatabases());

        $schemaManager->dropDatabase($path);

        self::assertFileDoesNotExist($path);
        self::assertFileDoesNotExist($path . '.wal');
        self::assertFalse($connection->isConnected());
    }

    /** @return iterable<string, array{string}> */
    public static function namesWithoutAFile(): iterable
    {
        yield 'empty' => [''];
        yield 'quoted empty' => ['""'];
        yield 'in-memory' => [':memory:'];
        yield 'quoted in-memory' => ['":memory:"'];
    }

    #[DataProvider('namesWithoutAFile')]
    public function testCreateDatabaseRefusesANameWithoutAFile(string $name): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Cannot create .*DuckDB database/');

        self::schemaManager(self::connect())->createDatabase($name);
    }

    #[DataProvider('namesWithoutAFile')]
    public function testDropDatabaseRefusesANameWithoutAFile(string $name): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Cannot drop .*DuckDB database/');

        self::schemaManager(self::connect())->dropDatabase($name);
    }

    private static function schemaManager(Connection $connection): DuckDBSchemaManager
    {
        $schemaManager = $connection->createSchemaManager();
        self::assertInstanceOf(DuckDBSchemaManager::class, $schemaManager);

        return $schemaManager;
    }
}
