<?php

declare(strict_types=1);

namespace MartinJuul\DBAL\DuckDB\Tests\Functional\Driver;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Name\OptionallyQualifiedName;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use MartinJuul\DBAL\DuckDB\Driver;
use MartinJuul\DBAL\DuckDB\Driver\Middleware\LogSchemaNotices;
use MartinJuul\DBAL\DuckDB\Driver\Middleware\OmitForeignKeys;
use MartinJuul\DBAL\DuckDB\Platform\DuckDBPlatform;
use MartinJuul\DBAL\DuckDB\Tests\Functional\Fixtures\RecordingLogger;
use MartinJuul\DBAL\DuckDB\Tests\Functional\FunctionalTestCase;
use Psr\Log\LogLevel;

use function sys_get_temp_dir;

final class LogSchemaNoticesMiddlewareTest extends FunctionalTestCase
{
    public function testThePlatformLogsToTheLogger(): void
    {
        $logger   = new RecordingLogger();
        $platform = self::platform([new LogSchemaNotices($logger)]);

        self::notice($platform);

        self::assertSame([
            [
                'level' => LogLevel::NOTICE,
                'message' => 'DuckDB drops the collation NOCASE of column app.t.code: its new type INTEGER has no '
                    . 'collation; only VARCHAR columns have one.',
                'context' => ['table' => 'app.t', 'column' => 'code', 'collation' => 'NOCASE', 'type' => 'INTEGER'],
            ],
        ], $logger->records);
    }

    /** Each middleware swaps in a platform copy that keeps the other's setting, in either order. */
    public function testCombinesWithOmitForeignKeysInEitherOrder(): void
    {
        $first  = new RecordingLogger();
        $second = new RecordingLogger();

        $loggedFirst  = self::platform([new LogSchemaNotices($first), new OmitForeignKeys()]);
        $loggedSecond = self::platform([new OmitForeignKeys(), new LogSchemaNotices($second)]);

        self::assertTrue($loggedFirst->omitsForeignKeys());
        self::assertTrue($loggedSecond->omitsForeignKeys());

        self::notice($loggedFirst);
        self::notice($loggedSecond);

        self::assertCount(1, $first->records);
        self::assertCount(1, $second->records);
    }

    public function testSelectingThePlatformDoesNotConnect(): void
    {
        $connection = DriverManager::getConnection(
            [
                'driverClass' => Driver::class,
                'dbname' => sys_get_temp_dir() . '/doctrine-duckdb-driver-missing-directory/never-created.duckdb',
            ],
            (new Configuration())->setMiddlewares([new LogSchemaNotices(new RecordingLogger())]),
        );

        self::assertInstanceOf(DuckDBPlatform::class, $connection->getDatabasePlatform());
        self::assertFalse($connection->isConnected());
    }

    public function testOtherPlatformsPassThroughUnchanged(): void
    {
        $connection = DriverManager::getConnection(
            ['driver' => 'pdo_sqlite', 'memory' => true],
            (new Configuration())->setMiddlewares([new LogSchemaNotices(new RecordingLogger())]),
        );

        self::assertInstanceOf(SQLitePlatform::class, $connection->getDatabasePlatform());
    }

    /** @param list<LogSchemaNotices|OmitForeignKeys> $middlewares */
    private static function platform(array $middlewares): DuckDBPlatform
    {
        $platform = DriverManager::getConnection(
            ['driverClass' => Driver::class, 'memory' => true],
            (new Configuration())->setMiddlewares($middlewares),
        )->getDatabasePlatform();

        self::assertInstanceOf(DuckDBPlatform::class, $platform);

        return $platform;
    }

    private static function notice(DuckDBPlatform $platform): void
    {
        $platform->noticeDroppedCollation(
            OptionallyQualifiedName::quoted('t', 'app'),
            Column::editor()->setUnquotedName('code')->setTypeName(Types::INTEGER)->create(),
            'NOCASE',
            Type::getType(Types::INTEGER)->getSQLDeclaration([], $platform),
        );
    }
}
