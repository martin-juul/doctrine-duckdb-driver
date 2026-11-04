<?php

declare(strict_types=1);

namespace MartinJuul\DBAL\DuckDB\Tests\Functional\Streaming;

use Doctrine\DBAL\Exception\DriverException;
use DuckDB\ErrorType;
use MartinJuul\DBAL\DuckDB\Driver\Exception\DriverError;
use MartinJuul\DBAL\DuckDB\Streaming\StreamingQuery;
use MartinJuul\DBAL\DuckDB\Tests\Functional\FunctionalTestCase;
use RuntimeException;

use function memory_get_peak_usage;

final class StreamingQueryTest extends FunctionalTestCase
{
    private const int PEAK_MEMORY_BOUND = 32 * 1024 * 1024;

    public function testIteratesAMillionRowsInBoundedMemory(): void
    {
        $connection = self::connect();
        $before     = memory_get_peak_usage();
        $count      = 0;
        $last       = -1;

        foreach (StreamingQuery::rows($connection, 'SELECT range AS id FROM range(1000000)') as $row) {
            $count++;
            $last = $row['id'];
        }

        self::assertSame(1000000, $count);
        self::assertSame(999999, $last);
        self::assertLessThan(self::PEAK_MEMORY_BOUND, memory_get_peak_usage() - $before);
    }

    public function testBreakingOutOfATemporaryIteratorReleasesTheConnection(): void
    {
        $connection = self::connect();

        foreach (StreamingQuery::rows($connection, 'SELECT range AS id FROM range(100000)') as $row) {
            self::assertSame(0, $row['id']);

            break;
        }

        self::assertSame(1, $connection->fetchOne('SELECT 1'));
    }

    public function testKeptIteratorBlocksTheConnectionUntilClosed(): void
    {
        $connection = self::connect();
        $rows       = StreamingQuery::rows($connection, 'SELECT range AS id FROM range(100000)');

        foreach ($rows as $row) {
            break;
        }

        try {
            $connection->fetchOne('SELECT 1');
            self::fail('The connection should refuse statements while the stream is open.');
        } catch (DriverException $e) {
            self::assertSame(ErrorType::ResourceInUse->value, $e->getCode());
        }

        $rows->close();

        self::assertSame(1, $connection->fetchOne('SELECT 1'));
    }

    public function testExceptionInConsumingLoopReleasesTheStream(): void
    {
        $connection = self::connect();

        try {
            foreach (StreamingQuery::rows($connection, 'SELECT range AS id FROM range(100000)') as $row) {
                throw new RuntimeException('boom');
            }
        } catch (RuntimeException) {
        }

        self::assertSame(1, $connection->fetchOne('SELECT 1'));
    }

    public function testIteratorClosesItselfAtTheEndOfIteration(): void
    {
        $connection = self::connect();
        $rows       = StreamingQuery::rows($connection, 'SELECT range AS id FROM range(3)');

        self::assertSame([['id' => 0], ['id' => 1], ['id' => 2]], [...$rows]);
        self::assertSame(1, $connection->fetchOne('SELECT 1'));
    }

    public function testBindsPositionalAndNamedParameters(): void
    {
        $connection = self::connect();

        $positional = [...StreamingQuery::rows($connection, 'SELECT ?::INTEGER + ?::INTEGER AS total', [2, 3])];
        $named      = [...StreamingQuery::rows($connection, 'SELECT $a::INTEGER * $b::INTEGER AS product', ['a' => 4, 'b' => 5])];

        self::assertSame([['total' => 5]], $positional);
        self::assertSame([['product' => 20]], $named);
    }

    public function testTemporalColumnsMatchDbalResultFormats(): void
    {
        $connection = self::connect();
        $sql        = "SELECT DATE '2024-03-05' AS d, TIME '10:11:12' AS t, TIMESTAMP '2024-03-05 10:11:12' AS ts";

        self::assertSame(
            $connection->fetchAssociative($sql),
            [...StreamingQuery::rows($connection, $sql)][0],
        );
    }

    public function testQueryErrorsSurfaceAsDriverExceptions(): void
    {
        $connection = self::connect();

        $this->expectException(DriverError::class);

        StreamingQuery::rows($connection, 'SELECT * FROM missing_table');
    }
}
