<?php

declare(strict_types=1);

namespace MartinJuul\DBAL\DuckDB\Types;

use Doctrine\DBAL\Exception\InvalidArgumentException;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;

use function is_string;
use function sprintf;

/**
 * An opaque passthrough for a DuckDB column type that has no DBAL mapping.
 *
 * The column's DuckDB type string is kept in the `duckdb_type` platform
 * option and rendered unchanged in DDL, so a schema compared against its own
 * introspection shows no change. Values pass through as the driver returns
 * them.
 */
final class NativeType extends Type
{
    public const string NAME = 'duckdb_native';

    /** The column platform option that holds the DuckDB type string. */
    public const string DECLARATION_OPTION = 'duckdb_type';

    /** Registers the type with DBAL unless it is registered already. */
    public static function register(): void
    {
        if (Type::hasType(self::NAME)) {
            return;
        }

        Type::addType(self::NAME, self::class);
    }

    /** @param array<string, mixed> $column */
    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        $declaration = $column[self::DECLARATION_OPTION] ?? null;

        if (! is_string($declaration) || $declaration === '') {
            throw new InvalidArgumentException(sprintf(
                'Column "%s" of type %s needs its DuckDB type string in the "%s" platform option.',
                is_string($column['name'] ?? null) ? $column['name'] : '',
                self::NAME,
                self::DECLARATION_OPTION,
            ));
        }

        return $declaration;
    }
}
