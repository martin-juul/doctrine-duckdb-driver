<?php

declare(strict_types=1);

namespace MartinJuul\DBAL\DuckDB\Types;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Exception\InvalidType;
use Doctrine\DBAL\Types\Type;
use DuckDB\Map;
use MartinJuul\DBAL\DuckDB\Schema\TypeStringParser;
use TypeError;
use ValueError;

use function is_array;

/**
 * A DuckDB MAP column, declared as `MAP(<key type>, <value type>)`.
 *
 * The key and value types are DuckDB type strings in the `key_type` and
 * `value_type` platform options, written as DuckDB reports them. Values are
 * PHP arrays whose keys are the map's keys; an array with keys 0 to n-1 is
 * still written as a map.
 *
 * @phpstan-import-type ParsedType from TypeStringParser
 */
final class MapType extends Type
{
    public const string NAME = 'duckdb_map';

    /** The column platform option that holds the key's DuckDB type string. */
    public const string KEY_TYPE_OPTION = 'key_type';

    /** The column platform option that holds the value's DuckDB type string. */
    public const string VALUE_TYPE_OPTION = 'value_type';

    /**
     * The platform options that declare an introspected MAP type.
     *
     * @param ParsedType $parsed
     *
     * @return array<string, mixed>
     */
    public static function platformOptionsFor(array $parsed): array
    {
        return [
            self::KEY_TYPE_OPTION => $parsed['children'][0]['type'],
            self::VALUE_TYPE_OPTION => $parsed['children'][1]['type'],
        ];
    }

    /** @param array<string, mixed> $column */
    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return 'MAP('
            . ColumnOptions::typeString($column, self::KEY_TYPE_OPTION, self::NAME)
            . ', '
            . ColumnOptions::typeString($column, self::VALUE_TYPE_OPTION, self::NAME)
            . ')';
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): Map|null
    {
        if ($value === null) {
            return null;
        }

        if (! is_array($value)) {
            throw InvalidType::new($value, self::NAME, ['null', 'array']);
        }

        [$pairs, $keyType, $valueType] = InferredType::forMap($value);

        try {
            return new Map($pairs, $keyType, $valueType);
        } catch (ValueError | TypeError $e) {
            throw InvalidType::new($value, self::NAME, ['null', 'array'], $e);
        }
    }
}
