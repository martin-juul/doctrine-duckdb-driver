<?php

declare(strict_types=1);

namespace MartinJuul\DBAL\DuckDB\Tests\Functional\Types;

use DateInterval;
use MartinJuul\DBAL\DuckDB\Types\IntervalType;

final class IntervalTypeTest extends TypeTestCase
{
    public function testIntervalsRoundTrip(): void
    {
        $this->createTable(self::valueColumn(IntervalType::NAME));

        self::assertSame('INTERVAL', $this->storedType());

        $read = $this->roundTrip(new DateInterval('P1M2DT3S'), IntervalType::NAME);

        self::assertInstanceOf(DateInterval::class, $read);
        self::assertSame('+0 1 2 0 0 3', $read->format('%R%y %m %d %h %i %s'));
        self::assertSame('1 month 2 days 00:00:03', $this->connection->fetchOne('SELECT CAST(v AS VARCHAR) FROM t'));

        $negative         = new DateInterval('P1Y2M3DT4H5M6S');
        $negative->invert = 1;

        $read = $this->roundTrip($negative, IntervalType::NAME);

        self::assertInstanceOf(DateInterval::class, $read);
        self::assertSame('-1 2 3 4 5 6', $read->format('%R%y %m %d %h %i %s'));

        self::assertNull($this->roundTrip(null, IntervalType::NAME));
    }

    public function testIntervalsWithMixedSignsReadAsNegativeParts(): void
    {
        $this->createTable(self::valueColumn(IntervalType::NAME));

        $this->connection->executeStatement("INSERT INTO t VALUES (1, INTERVAL '1 month -1 day -90 seconds')");

        $read = $this->connection->convertToPHPValue(
            $this->connection->fetchOne('SELECT v FROM t'),
            IntervalType::NAME,
        );

        self::assertInstanceOf(DateInterval::class, $read);
        self::assertSame([0, 1, -1, 0, -1, -30], [$read->y, $read->m, $read->d, $read->h, $read->i, $read->s]);
    }

    public function testIntrospectsWithoutChanges(): void
    {
        $declared = $this->createTable(self::valueColumn(IntervalType::NAME));

        $this->assertIntrospectsUnchanged($declared, []);
    }
}
