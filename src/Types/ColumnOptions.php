<?php

declare(strict_types=1);

namespace MartinJuul\DBAL\DuckDB\Types;

use Doctrine\DBAL\Exception\InvalidArgumentException;

use function is_array;
use function is_string;
use function sprintf;
use function str_replace;

/**
 * Reads the DuckDB type strings that the nested types keep in column platform
 * options.
 *
 * @internal
 */
final class ColumnOptions
{
    /**
     * A DuckDB type string kept in a platform option.
     *
     * @param array<string, mixed> $column
     *
     * @return non-empty-string
     */
    public static function typeString(array $column, string $option, string $typeName): string
    {
        $type = $column[$option] ?? null;

        if (! is_string($type) || $type === '') {
            throw self::missing($column, $option, $typeName, 'a DuckDB type string');
        }

        return $type;
    }

    /**
     * An ordered map of names to DuckDB type strings kept in a platform option.
     *
     * @param array<string, mixed> $column
     *
     * @return non-empty-array<string, non-empty-string>
     */
    public static function namedTypeStrings(array $column, string $option, string $typeName): array
    {
        $fields = $column[$option] ?? null;

        if (! is_array($fields) || $fields === []) {
            throw self::missing($column, $option, $typeName, 'its field names mapped to DuckDB type strings');
        }

        $types = [];

        foreach ($fields as $name => $type) {
            if (! is_string($type) || $type === '') {
                throw self::missing($column, $option, $typeName, 'its field names mapped to DuckDB type strings');
            }

            $types[(string) $name] = $type;
        }

        return $types;
    }

    /** Quotes a STRUCT field name. */
    public static function quoteName(string $name): string
    {
        return '"' . str_replace('"', '""', $name) . '"';
    }

    /** @param array<string, mixed> $column */
    private static function missing(
        array $column,
        string $option,
        string $typeName,
        string $expected,
    ): InvalidArgumentException {
        return new InvalidArgumentException(sprintf(
            'Column "%s" of type %s needs %s in the "%s" platform option.',
            is_string($column['name'] ?? null) ? $column['name'] : '',
            $typeName,
            $expected,
            $option,
        ));
    }
}
