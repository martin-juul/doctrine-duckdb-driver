<?php

declare(strict_types=1);

namespace MartinJuul\DBAL\DuckDB\Types;

use DateInterval;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\DateIntervalType;
use Doctrine\DBAL\Types\Exception\InvalidType;
use Doctrine\DBAL\Types\Exception\ValueNotConvertible;
use Doctrine\DBAL\Types\Type;
use DuckDB\Interval;

use function get_debug_type;
use function intdiv;
use function is_string;
use function round;

/**
 * A DuckDB INTERVAL column, read and written as PHP DateInterval objects.
 *
 * Writes keep the interval's months (years and months), days and time,
 * including microseconds. The driver reads an interval whose parts share one
 * sign in DBAL's dateinterval format, which has whole seconds; an interval
 * with mixed signs, such as `1 month -1 day`, becomes a DateInterval with
 * negative parts.
 */
final class IntervalType extends Type
{
    public const string NAME = 'duckdb_interval';

    private const int MICROS_PER_SECOND = 1_000_000;

    /** @param array<string, mixed> $column */
    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return 'INTERVAL';
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): Interval|null
    {
        if ($value === null) {
            return null;
        }

        if (! $value instanceof DateInterval) {
            throw InvalidType::new($value, self::NAME, ['null', DateInterval::class]);
        }

        $sign    = $value->invert === 1 ? -1 : 1;
        $months  = $value->y * 12 + $value->m;
        $seconds = $value->h * 3600 + $value->i * 60 + $value->s;
        $micros  = $seconds * self::MICROS_PER_SECOND + (int) round($value->f * self::MICROS_PER_SECOND);

        return new Interval($sign * $months, $sign * $value->d, $sign * $micros);
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): DateInterval|null
    {
        if ($value === null || $value instanceof DateInterval) {
            return $value;
        }

        if (is_string($value)) {
            return (new DateIntervalType())->convertToPHPValue($value, $platform);
        }

        if ($value instanceof Interval) {
            return self::fromMixedSigns($value);
        }

        throw ValueNotConvertible::new(get_debug_type($value), self::NAME);
    }

    private static function fromMixedSigns(Interval $value): DateInterval
    {
        $months  = $value->getMonths();
        $micros  = $value->getMicros();
        $seconds = intdiv($micros, self::MICROS_PER_SECOND);

        $interval    = new DateInterval('PT0S');
        $interval->y = intdiv($months, 12);
        $interval->m = $months % 12;
        $interval->d = $value->getDays();
        $interval->h = intdiv($seconds, 3600);
        $interval->i = intdiv($seconds % 3600, 60);
        $interval->s = $seconds % 60;
        $interval->f = ($micros % self::MICROS_PER_SECOND) / self::MICROS_PER_SECOND;

        return $interval;
    }
}
