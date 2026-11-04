<?php

declare(strict_types=1);

namespace MartinJuul\DBAL\DuckDB\Types;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Exception\InvalidType;
use Doctrine\DBAL\Types\Type;
use DuckDB\Struct;
use MartinJuul\DBAL\DuckDB\Schema\TypeStringParser;
use TypeError;
use ValueError;

use function implode;
use function is_array;

/**
 * A DuckDB STRUCT column.
 *
 * The `fields` platform option maps each field name, in declaration order, to
 * its DuckDB type string, written as DuckDB reports it. Values are associative
 * arrays keyed by field name.
 *
 * @phpstan-import-type ParsedType from TypeStringParser
 */
final class StructType extends Type
{
    public const string NAME = 'duckdb_struct';

    /** The column platform option that maps field names to DuckDB type strings. */
    public const string FIELDS_OPTION = 'fields';

    /**
     * The platform options that declare an introspected STRUCT type.
     *
     * @param ParsedType $parsed
     *
     * @return array<string, mixed>
     */
    public static function platformOptionsFor(array $parsed): array
    {
        $fields = [];

        foreach ($parsed['children'] as $child) {
            $fields[(string) $child['name']] = $child['type'];
        }

        return [self::FIELDS_OPTION => $fields];
    }

    /** @param array<string, mixed> $column */
    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        $fields = [];

        foreach (ColumnOptions::namedTypeStrings($column, self::FIELDS_OPTION, self::NAME) as $name => $type) {
            $fields[] = ColumnOptions::quoteName($name) . ' ' . $type;
        }

        return 'STRUCT(' . implode(', ', $fields) . ')';
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): Struct|null
    {
        if ($value === null) {
            return null;
        }

        if (! is_array($value)) {
            throw InvalidType::new($value, self::NAME, ['null', 'array']);
        }

        [$fields, $types] = InferredType::forStruct($value);

        try {
            return new Struct($fields, $types);
        } catch (ValueError | TypeError $e) {
            throw InvalidType::new($value, self::NAME, ['null', 'array'], $e);
        }
    }
}
