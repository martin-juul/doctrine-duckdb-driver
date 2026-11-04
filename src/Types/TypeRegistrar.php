<?php

declare(strict_types=1);

namespace MartinJuul\DBAL\DuckDB\Types;

use Doctrine\DBAL\Types\Type;

/**
 * Registers the package's DBAL types: LIST, STRUCT, MAP, HUGEINT, INTERVAL
 * and the opaque passthrough type for other DuckDB types.
 */
final class TypeRegistrar
{
    public const array TYPES = [
        ListType::NAME => ListType::class,
        StructType::NAME => StructType::class,
        MapType::NAME => MapType::class,
        HugeIntType::NAME => HugeIntType::class,
        IntervalType::NAME => IntervalType::class,
        NativeType::NAME => NativeType::class,
    ];

    /** Registers each type with DBAL unless a type of that name is registered already. */
    public static function register(): void
    {
        foreach (self::TYPES as $name => $class) {
            if (Type::hasType($name)) {
                continue;
            }

            Type::addType($name, $class);
        }
    }
}
