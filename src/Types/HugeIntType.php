<?php

declare(strict_types=1);

namespace MartinJuul\DBAL\DuckDB\Types;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Exception\InvalidType;
use Doctrine\DBAL\Types\Exception\ValueNotConvertible;
use Doctrine\DBAL\Types\Type;
use DuckDB\HugeInt;
use TypeError;
use ValueError;

use function get_debug_type;
use function is_int;
use function is_string;

/**
 * A DuckDB HUGEINT column: a 128-bit signed integer.
 *
 * Values are always read as PHP strings, since most exceed PHP's integer
 * range. Integers and numeric strings can be written.
 */
final class HugeIntType extends Type
{
    public const string NAME = 'duckdb_hugeint';

    /** @param array<string, mixed> $column */
    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return 'HUGEINT';
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): HugeInt|null
    {
        if ($value === null) {
            return null;
        }

        if (! is_int($value) && ! is_string($value)) {
            throw InvalidType::new($value, self::NAME, ['null', 'int', 'string']);
        }

        try {
            return new HugeInt($value);
        } catch (ValueError | TypeError $e) {
            throw InvalidType::new($value, self::NAME, ['null', 'int', 'string'], $e);
        }
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): string|null
    {
        if ($value === null || is_string($value)) {
            return $value;
        }

        if (is_int($value)) {
            return (string) $value;
        }

        throw ValueNotConvertible::new(get_debug_type($value), self::NAME);
    }
}
