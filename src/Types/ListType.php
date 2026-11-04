<?php

declare(strict_types=1);

namespace MartinJuul\DBAL\DuckDB\Types;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Exception\InvalidType;
use Doctrine\DBAL\Types\Type;
use DuckDB\ListValue;
use MartinJuul\DBAL\DuckDB\Schema\TypeStringParser;
use TypeError;
use ValueError;

use function array_is_list;
use function is_array;

/**
 * A DuckDB LIST column, declared as `<element type>[]`.
 *
 * The element type is a DuckDB type string in the `element_type` platform
 * option, written as DuckDB reports it (`INTEGER`, `STRUCT(a INTEGER)[]`), so
 * that introspection finds the same declaration. Values are PHP lists.
 *
 * @phpstan-import-type ParsedType from TypeStringParser
 */
final class ListType extends Type
{
    public const string NAME = 'duckdb_list';

    /** The column platform option that holds the element's DuckDB type string. */
    public const string ELEMENT_TYPE_OPTION = 'element_type';

    /**
     * The platform options that declare an introspected LIST type.
     *
     * @param ParsedType $parsed
     *
     * @return array<string, mixed>
     */
    public static function platformOptionsFor(array $parsed): array
    {
        return [self::ELEMENT_TYPE_OPTION => $parsed['children'][0]['type']];
    }

    /** @param array<string, mixed> $column */
    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return ColumnOptions::typeString($column, self::ELEMENT_TYPE_OPTION, self::NAME) . '[]';
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ListValue|null
    {
        if ($value === null) {
            return null;
        }

        if (! is_array($value) || ! array_is_list($value)) {
            throw InvalidType::new($value, self::NAME, ['null', 'list']);
        }

        [$elements, $elementType] = InferredType::forList($value);

        try {
            return new ListValue($elements, $elementType);
        } catch (ValueError | TypeError $e) {
            throw InvalidType::new($value, self::NAME, ['null', 'list'], $e);
        }
    }
}
