<?php

declare(strict_types=1);

namespace MartinJuul\DBAL\DuckDB\Tests\Functional\Driver;

use DateInterval;
use DateTime;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use MartinJuul\DBAL\DuckDB\Tests\Functional\FunctionalTestCase;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;

use function date_default_timezone_get;
use function date_default_timezone_set;

final class ResultTest extends FunctionalTestCase
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

    public function testFetchOneThenAnotherQueryOnTheSameConnection(): void
    {
        $connection = self::connect();
        $connection->executeStatement('CREATE TABLE t AS SELECT range AS id FROM range(10)');

        self::assertSame(0, $connection->fetchOne('SELECT * FROM t ORDER BY id'));
        self::assertSame(10, $connection->fetchOne('SELECT count(*) FROM t'));
    }

    public function testDateAndTimeTypesRoundTripThroughDbal(): void
    {
        $connection = self::connect();
        $platform   = $connection->getDatabasePlatform();
        $connection->executeStatement(
            'CREATE TABLE t (d DATE, t TIME, ts TIMESTAMP, tsn TIMESTAMP_NS, tz TIMESTAMPTZ)',
        );

        $date     = new DateTime('2026-03-04 00:00:00');
        $time     = new DateTime('1970-01-01 12:34:56');
        $dateTime = new DateTime('2026-03-04 05:06:07');
        $instant  = new DateTime('2026-07-08 09:10:11+02:00');

        $connection->insert(
            't',
            ['d' => $date, 't' => $time, 'ts' => $dateTime, 'tsn' => $dateTime, 'tz' => $instant],
            [Types::DATE_MUTABLE, Types::TIME_MUTABLE, Types::DATETIME_MUTABLE, Types::DATETIME_MUTABLE, Types::DATETIMETZ_MUTABLE],
        );

        $row = $connection->fetchAssociative('SELECT * FROM t');
        self::assertIsArray($row);

        self::assertEquals($date, Type::getType(Types::DATE_MUTABLE)->convertToPHPValue($row['d'], $platform));
        self::assertEquals($time, Type::getType(Types::TIME_MUTABLE)->convertToPHPValue($row['t'], $platform));
        self::assertEquals($dateTime, Type::getType(Types::DATETIME_MUTABLE)->convertToPHPValue($row['ts'], $platform));
        self::assertEquals($dateTime, Type::getType(Types::DATETIME_MUTABLE)->convertToPHPValue($row['tsn'], $platform));

        $readInstant = Type::getType(Types::DATETIMETZ_MUTABLE)->convertToPHPValue($row['tz'], $platform);
        self::assertInstanceOf(DateTime::class, $readInstant);
        self::assertSame($instant->getTimestamp(), $readInstant->getTimestamp());

        self::assertEquals(
            new DateTimeImmutable('2026-03-04 05:06:07'),
            Type::getType(Types::DATETIME_IMMUTABLE)->convertToPHPValue($row['ts'], $platform),
        );
    }

    public function testMicrosecondsSurviveInDateTime(): void
    {
        $connection = self::connect();

        $value = $connection->fetchOne("SELECT TIMESTAMP '2026-01-02 03:04:05.123456'");

        self::assertSame('2026-01-02 03:04:05.123456', $value);
        self::assertEquals(
            new DateTime('2026-01-02 03:04:05.123456'),
            Type::getType(Types::DATETIME_MUTABLE)->convertToPHPValue($value, $connection->getDatabasePlatform()),
        );
    }

    public function testIntervalConvertsThroughTheDateIntervalType(): void
    {
        $connection = self::connect();

        $value    = $connection->fetchOne("SELECT INTERVAL '1 month 2 days 3 seconds'");
        $interval = Type::getType(Types::DATEINTERVAL)->convertToPHPValue($value, $connection->getDatabasePlatform());

        self::assertInstanceOf(DateInterval::class, $interval);
        self::assertSame('+0-1-2 0:0:3', $interval->format('%R%y-%m-%d %h:%i:%s'));
    }

    public function testInfiniteTimestampIsTheStringInfinity(): void
    {
        self::assertSame('infinity', self::connect()->fetchOne("SELECT 'infinity'::TIMESTAMP"));
    }

    public function testWideNumbersAreExactStrings(): void
    {
        $row = self::connect()->fetchNumeric(
            'SELECT 1234567890123456789012345678.0123456789::DECIMAL(38,10), '
            . '170141183460469231731687303715884105727::HUGEINT, 18446744073709551615::UBIGINT',
        );

        self::assertSame(
            ['1234567890123456789012345678.0123456789', '170141183460469231731687303715884105727', '18446744073709551615'],
            $row,
        );
    }

    public function testColumnNamesAndCounts(): void
    {
        $result = self::connect()->executeQuery('SELECT 1 AS a, 2 AS b');

        self::assertSame(2, $result->columnCount());
        self::assertSame('b', $result->getColumnName(1));
        self::assertSame([['a' => 1, 'b' => 2]], $result->fetchAllAssociative());
    }
}
