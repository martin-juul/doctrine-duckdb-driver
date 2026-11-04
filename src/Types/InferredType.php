<?php

declare(strict_types=1);

namespace MartinJuul\DBAL\DuckDB\Types;

use DuckDB\Interval;
use DuckDB\Value;

use function array_is_list;
use function array_keys;
use function implode;
use function in_array;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_string;

/**
 * The DuckDB type of a PHP value, inferred from the value alone.
 *
 * DBAL converts a value without seeing its column, so the nested types build
 * ext-duckdb typed values from the value's shape and let DuckDB cast them to
 * the column's type. Integers become BIGINT, floats DOUBLE, booleans BOOLEAN
 * and strings VARCHAR; integers mixed with floats widen to DOUBLE and any
 * other mix of scalars to VARCHAR. A sequential array is a LIST, an array with
 * only string keys a STRUCT, and any other array a MAP. Structs with different
 * fields combine into a MAP, since DuckDB casts STRUCT to MAP but not back.
 * A type that no value determines (an empty list or only nulls) is VARCHAR.
 *
 * @internal
 */
final class InferredType
{
    private const string SCALAR = 'scalar';
    private const string LIST   = 'list';
    private const string STRUCT = 'struct';
    private const string MAP    = 'map';

    /** @param array<string, self|null> $fields */
    private function __construct(
        private readonly string $kind,
        private readonly string $scalar = '',
        private readonly self|null $key = null,
        private readonly self|null $element = null,
        private readonly array $fields = [],
    ) {
    }

    /**
     * The prepared elements of a list and the SQL type of its elements.
     *
     * @param list<mixed> $list
     *
     * @return array{list<mixed>, non-empty-string}
     */
    public static function forList(array $list): array
    {
        $element = self::unifyAll($list);

        return [self::prepareList($list, $element), self::toSQL($element)];
    }

    /**
     * The prepared fields of a struct and the SQL type of each field.
     *
     * @param array<mixed> $struct
     *
     * @return array{array<mixed>, array<non-empty-string>}
     */
    public static function forStruct(array $struct): array
    {
        $values = [];
        $types  = [];

        foreach ($struct as $name => $value) {
            $type = self::of($value);

            $values[$name] = self::prepare($value, $type);
            $types[$name]  = self::toSQL($type);
        }

        return [$values, $types];
    }

    /**
     * The key/value pairs of a map, as ext-duckdb takes them, and the SQL types of its keys and values.
     *
     * @param array<mixed> $map
     *
     * @return array{list<array{key: mixed, value: mixed}>, non-empty-string, non-empty-string}
     */
    public static function forMap(array $map): array
    {
        $type = self::ofMap($map);

        return [self::preparePairs($map, $type), self::toSQL($type->key), self::toSQL($type->element)];
    }

    private static function of(mixed $value): self|null
    {
        return match (true) {
            $value === null => null,
            is_bool($value) => new self(self::SCALAR, 'BOOLEAN'),
            is_int($value) => new self(self::SCALAR, 'BIGINT'),
            is_float($value) => new self(self::SCALAR, 'DOUBLE'),
            $value instanceof Value => new self(self::SCALAR, $value->getType()),
            $value instanceof Interval => new self(self::SCALAR, 'INTERVAL'),
            is_array($value) => self::ofArray($value),
            default => new self(self::SCALAR, 'VARCHAR'),
        };
    }

    /** @param array<mixed> $value */
    private static function ofArray(array $value): self
    {
        if (array_is_list($value)) {
            return new self(self::LIST, element: self::unifyAll($value));
        }

        $fields = [];

        foreach ($value as $name => $item) {
            if (! is_string($name)) {
                return self::ofMap($value);
            }

            $fields[$name] = self::of($item);
        }

        return new self(self::STRUCT, fields: $fields);
    }

    /** @param array<mixed> $value */
    private static function ofMap(array $value): self
    {
        $key = null;

        foreach (array_keys($value) as $name) {
            $key = self::unify($key, self::of($name));
        }

        return new self(self::MAP, key: $key, element: self::unifyAll($value));
    }

    /** @param array<mixed> $values */
    private static function unifyAll(array $values): self|null
    {
        $type = null;

        foreach ($values as $value) {
            $type = self::unify($type, self::of($value));
        }

        return $type;
    }

    private static function unify(self|null $a, self|null $b): self|null
    {
        if ($a === null) {
            return $b;
        }

        if ($b === null) {
            return $a;
        }

        if ($a->kind === self::SCALAR && $b->kind === self::SCALAR) {
            return self::unifyScalars($a, $b);
        }

        if ($a->kind === self::LIST && $b->kind === self::LIST) {
            return new self(self::LIST, element: self::unify($a->element, $b->element));
        }

        if ($a->kind === self::STRUCT && $b->kind === self::STRUCT && array_keys($a->fields) === array_keys($b->fields)) {
            $fields = [];

            foreach ($a->fields as $name => $type) {
                $fields[$name] = self::unify($type, $b->fields[$name]);
            }

            return new self(self::STRUCT, fields: $fields);
        }

        if ($a->isMapLike() && $b->isMapLike()) {
            return new self(
                self::MAP,
                key: self::unify($a->mapKey(), $b->mapKey()),
                element: self::unify($a->mapValue(), $b->mapValue()),
            );
        }

        // A scalar mixed with a nested value has no common type; DuckDB rejects the value when it is bound.
        return new self(self::SCALAR, 'VARCHAR');
    }

    private static function unifyScalars(self $a, self $b): self
    {
        if ($a->scalar === $b->scalar) {
            return $a;
        }

        $numeric = ['BIGINT', 'DOUBLE'];

        if (in_array($a->scalar, $numeric, true) && in_array($b->scalar, $numeric, true)) {
            return new self(self::SCALAR, 'DOUBLE');
        }

        return new self(self::SCALAR, 'VARCHAR');
    }

    private function isMapLike(): bool
    {
        return $this->kind === self::STRUCT || $this->kind === self::MAP;
    }

    private function mapKey(): self|null
    {
        if ($this->kind === self::STRUCT) {
            return new self(self::SCALAR, 'VARCHAR');
        }

        return $this->key;
    }

    private function mapValue(): self|null
    {
        if ($this->kind !== self::STRUCT) {
            return $this->element;
        }

        $value = null;

        foreach ($this->fields as $field) {
            $value = self::unify($value, $field);
        }

        return $value;
    }

    /** @return non-empty-string */
    private static function toSQL(self|null $type): string
    {
        if ($type === null) {
            return 'VARCHAR';
        }

        if ($type->kind === self::LIST) {
            return self::toSQL($type->element) . '[]';
        }

        if ($type->kind === self::MAP) {
            return 'MAP(' . self::toSQL($type->key) . ', ' . self::toSQL($type->element) . ')';
        }

        if ($type->kind === self::STRUCT) {
            $fields = [];

            foreach ($type->fields as $name => $field) {
                $fields[] = ColumnOptions::quoteName($name) . ' ' . self::toSQL($field);
            }

            return 'STRUCT(' . implode(', ', $fields) . ')';
        }

        return $type->scalar === '' ? 'VARCHAR' : $type->scalar;
    }

    /** Reshapes a value for the inferred type: maps become ext-duckdb's key/value pairs. */
    private static function prepare(mixed $value, self|null $type): mixed
    {
        if ($type === null || ! is_array($value)) {
            return $value;
        }

        if ($type->kind === self::LIST) {
            return self::prepareList($value, $type->element);
        }

        if ($type->kind === self::MAP) {
            return self::preparePairs($value, $type);
        }

        if ($type->kind === self::STRUCT) {
            $fields = [];

            foreach ($value as $name => $item) {
                $fields[$name] = self::prepare($item, $type->fields[$name] ?? null);
            }

            return $fields;
        }

        return $value;
    }

    /**
     * @param array<mixed> $list
     *
     * @return list<mixed>
     */
    private static function prepareList(array $list, self|null $element): array
    {
        $prepared = [];

        foreach ($list as $item) {
            $prepared[] = self::prepare($item, $element);
        }

        return $prepared;
    }

    /**
     * @param array<mixed> $map
     *
     * @return list<array{key: mixed, value: mixed}>
     */
    private static function preparePairs(array $map, self $type): array
    {
        $pairs = [];

        foreach ($map as $key => $value) {
            $pairs[] = [
                'key' => self::prepare($key, $type->key),
                'value' => self::prepare($value, $type->element),
            ];
        }

        return $pairs;
    }
}
