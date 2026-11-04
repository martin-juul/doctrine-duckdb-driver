<?php

declare(strict_types=1);

namespace MartinJuul\DBAL\DuckDB\Tests\Unit\Driver;

use DateTimeImmutable;
use DateTimeZone;
use DuckDB\Interval;
use MartinJuul\DBAL\DuckDB\Driver\ValueNormalizer;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function date_default_timezone_get;
use function date_default_timezone_set;

final class ValueNormalizerTest extends TestCase
{
    private string $timezone;

    #[Before]
    protected function useNonUtcZone(): void
    {
        $this->timezone = date_default_timezone_get();
        date_default_timezone_set('Europe/Copenhagen');
    }

    #[After]
    protected function restoreZone(): void
    {
        date_default_timezone_set($this->timezone);
    }

    /** @return iterable<string, array{string, mixed, mixed}> */
    public static function values(): iterable
    {
        $utc = new DateTimeZone('UTC');

        yield 'date' => ['DATE', new DateTimeImmutable('2026-01-02 00:00:00', $utc), '2026-01-02'];
        yield 'timestamp' => ['TIMESTAMP', new DateTimeImmutable('2026-01-02 03:04:05', $utc), '2026-01-02 03:04:05'];
        yield 'timestamp with micros' => [
            'TIMESTAMP',
            new DateTimeImmutable('2026-01-02 03:04:05.123456', $utc),
            '2026-01-02 03:04:05.123456',
        ];

        yield 'timestamp_ns' => ['TIMESTAMP_NS', new DateTimeImmutable('2026-01-02 03:04:05.5', $utc), '2026-01-02 03:04:05.500000'];
        yield 'timestamptz in the default zone' => [
            'TIMESTAMP_TZ',
            new DateTimeImmutable('2026-01-02 03:04:05', $utc),
            '2026-01-02 04:04:05+0100',
        ];

        yield 'time' => ['TIME', '12:34:56.500000', '12:34:56'];
        yield 'interval' => ['INTERVAL', new Interval(14, 2, 3_500_000), '+P01Y02M02DT00H00M03S'];
        yield 'negative interval' => ['INTERVAL', new Interval(0, -1, -60_000_000), '-P00Y00M01DT00H01M00S'];
        yield 'infinite timestamp' => ['TIMESTAMP', 'infinity', 'infinity'];
        yield 'decimal' => ['DECIMAL(10,2)', '1.50', '1.50'];
        yield 'list' => ['INTEGER[]', [1, 2], [1, 2]];
        yield 'null date' => ['DATE', null, null];
    }

    #[DataProvider('values')]
    public function testNormalizesByColumnType(string $type, mixed $value, mixed $expected): void
    {
        self::assertSame($expected, ValueNormalizer::forColumnType($type)($value));
    }

    public function testMixedSignIntervalPassesThrough(): void
    {
        $interval = new Interval(1, -1, 0);

        self::assertSame($interval, ValueNormalizer::forColumnType('INTERVAL')($interval));
    }
}
