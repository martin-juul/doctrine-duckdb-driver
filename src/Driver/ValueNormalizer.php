<?php

declare(strict_types=1);

namespace MartinJuul\DBAL\DuckDB\Driver;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use DuckDB\Interval;

use function abs;
use function date_default_timezone_get;
use function intdiv;
use function is_string;
use function sprintf;
use function str_ends_with;
use function str_starts_with;
use function substr;

/**
 * Converts the PHP values ext-duckdb returns into the shapes DBAL's types
 * expect, decided by the column's DuckDB type name.
 *
 * ext-duckdb returns DATE and every TIMESTAMP flavour as UTC DateTimeImmutable
 * objects and INTERVAL as DuckDB\Interval. DBAL types parse strings, so those
 * become strings in the platform's formats. Everything else passes through.
 */
final class ValueNormalizer
{
    private static string|null $timeZoneName = null;

    private static DateTimeZone|null $timeZone = null;

    /** @return Closure(mixed): mixed */
    public static function forColumnType(string $type): Closure
    {
        return self::converterFor($type) ?? static fn (mixed $value): mixed => $value;
    }

    /**
     * The conversion that values of a column of the type need, or null when
     * they pass through unchanged.
     *
     * @return (Closure(mixed): mixed)|null
     */
    public static function converterFor(string $type): Closure|null
    {
        return match (true) {
            $type === 'DATE' => static fn (mixed $value): mixed => $value instanceof DateTimeImmutable
                ? $value->format('Y-m-d')
                : $value,
            $type === 'TIMESTAMP_TZ' => static fn (mixed $value): mixed => $value instanceof DateTimeImmutable
                ? self::formatTimestampTz($value)
                : $value,
            str_starts_with($type, 'TIMESTAMP') => static fn (mixed $value): mixed => $value instanceof DateTimeImmutable
                ? self::formatTimestamp($value)
                : $value,
            $type === 'TIME' || $type === 'TIME_NS' => static fn (mixed $value): mixed => self::formatTime($value),
            $type === 'INTERVAL' => static fn (mixed $value): mixed => $value instanceof Interval
                ? self::formatInterval($value)
                : $value,
            default => null,
        };
    }

    /** A naive timestamp: ext-duckdb labels it UTC, so its UTC wall clock is the stored value. */
    private static function formatTimestamp(DateTimeImmutable $value): string
    {
        $formatted = $value->format('Y-m-d H:i:s.u');

        if (str_ends_with($formatted, '.000000')) {
            return substr($formatted, 0, -7);
        }

        return $formatted;
    }

    /** An instant, rendered in PHP's default time zone with its offset. */
    private static function formatTimestampTz(DateTimeImmutable $value): string
    {
        $name = date_default_timezone_get();

        if (self::$timeZone === null || self::$timeZoneName !== $name) {
            self::$timeZone     = new DateTimeZone($name);
            self::$timeZoneName = $name;
        }

        return $value->setTimezone(self::$timeZone)->format('Y-m-d H:i:sO');
    }

    private static function formatTime(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        // ext-duckdb renders TIME as HH:MM:SS[.ffffff]; DBAL's time format is H:i:s.
        if (! is_string($value)) {
            return $value;
        }

        return substr($value, 0, 8);
    }

    /**
     * DBAL's dateinterval format is "%RP%YY%MM%DDT%HH%IM%SS". Sub-second parts are
     * dropped. An interval whose parts have mixed signs has no such rendering and
     * passes through unchanged.
     */
    private static function formatInterval(Interval $interval): Interval|string
    {
        $months = $interval->getMonths();
        $days   = $interval->getDays();
        $micros = $interval->getMicros();

        $negative = $months <= 0 && $days <= 0 && $micros <= 0 && ($months < 0 || $days < 0 || $micros < 0);
        $positive = $months >= 0 && $days >= 0 && $micros >= 0;

        if (! $negative && ! $positive) {
            return $interval;
        }

        $months  = abs($months);
        $days    = abs($days);
        $seconds = intdiv(abs($micros), 1_000_000);

        return sprintf(
            '%sP%02dY%02dM%02dDT%02dH%02dM%02dS',
            $negative ? '-' : '+',
            intdiv($months, 12),
            $months % 12,
            $days,
            intdiv($seconds, 3600),
            intdiv($seconds % 3600, 60),
            $seconds % 60,
        );
    }
}
