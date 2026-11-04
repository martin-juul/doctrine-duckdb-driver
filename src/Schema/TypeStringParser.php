<?php

declare(strict_types=1);

namespace MartinJuul\DBAL\DuckDB\Schema;

use InvalidArgumentException;

use function ctype_digit;
use function preg_match;
use function preg_replace;
use function sprintf;
use function str_ends_with;
use function str_replace;
use function str_starts_with;
use function strlen;
use function strpos;
use function strtoupper;
use function substr;
use function trim;

/**
 * Parses the type strings DuckDB reports in its catalog, such as
 * `DECIMAL(18,4)`, `INTEGER[]`, `INTEGER[3]`, `STRUCT(a INTEGER, b VARCHAR)`,
 * `MAP(VARCHAR, INTEGER)` or `ENUM('a', 'b')`.
 *
 * A parse covers one level. Nested types are returned as declarations in
 * `children`, which callers parse again when they need them.
 *
 * - `name`: the upper-cased base name. `X[]` is `LIST` and `X[n]` is `ARRAY`.
 * - `declaration`: the type string as given, trimmed.
 * - `arguments`: integer modifiers, e.g. `[18, 4]` for `DECIMAL(18,4)` or the
 *   size of an `ARRAY`.
 * - `values`: string literals, e.g. the labels of an `ENUM`.
 * - `children`: element types. One unnamed child for `LIST` and `ARRAY`, the
 *   key and value for `MAP`, named fields or members for `STRUCT` and `UNION`.
 *
 * @phpstan-type ParsedType array{
 *     name: non-empty-string,
 *     declaration: non-empty-string,
 *     arguments: list<int>,
 *     values: list<string>,
 *     children: list<array{name: string|null, type: non-empty-string}>,
 * }
 */
final class TypeStringParser
{
    /** @return ParsedType */
    public static function parse(string $type): array
    {
        $declaration = trim($type);

        if ($declaration === '') {
            throw new InvalidArgumentException('Cannot parse an empty DuckDB type string.');
        }

        if (str_ends_with($declaration, ']')) {
            return self::parseCollection($declaration);
        }

        $open = strpos($declaration, '(');

        if ($open === false) {
            return self::result(self::normalizeName($declaration, $declaration), $declaration);
        }

        if (! str_ends_with($declaration, ')')) {
            throw self::malformed($declaration);
        }

        $name      = self::normalizeName(substr($declaration, 0, $open), $declaration);
        $arguments = self::splitTopLevel(substr($declaration, $open + 1, -1), $declaration);

        if ($name === 'STRUCT' || $name === 'UNION') {
            $children = [];

            foreach ($arguments as $argument) {
                $children[] = self::parseField($argument, $declaration);
            }

            return self::result($name, $declaration, children: $children);
        }

        $integers = [];
        $values   = [];
        $children = [];

        foreach ($arguments as $argument) {
            if (preg_match('/^-?\d+$/', $argument) === 1) {
                $integers[] = (int) $argument;

                continue;
            }

            if (str_starts_with($argument, "'")) {
                $values[] = self::unquote($argument, "'", $declaration);

                continue;
            }

            $children[] = ['name' => null, 'type' => $argument];
        }

        return self::result($name, $declaration, $integers, $values, $children);
    }

    /**
     * Parses `X[]` and `X[n]`.
     *
     * @param non-empty-string $declaration
     *
     * @return ParsedType
     */
    private static function parseCollection(string $declaration): array
    {
        $open = self::findSuffixBracket($declaration);

        $element = trim(substr($declaration, 0, $open));
        $size    = substr($declaration, $open + 1, -1);

        if ($element === '') {
            throw self::malformed($declaration);
        }

        $children = [['name' => null, 'type' => $element]];

        if ($size === '') {
            return self::result('LIST', $declaration, children: $children);
        }

        if (! ctype_digit($size)) {
            throw self::malformed($declaration);
        }

        return self::result('ARRAY', $declaration, [(int) $size], children: $children);
    }

    /**
     * Finds the `[` that opens the trailing `[...]` of a declaration.
     *
     * @param non-empty-string $declaration
     */
    private static function findSuffixBracket(string $declaration): int
    {
        $depth = 0;

        for ($position = strlen($declaration) - 1; $position >= 0; $position--) {
            $character = $declaration[$position];

            if ($character === ']') {
                $depth++;

                continue;
            }

            if ($character !== '[') {
                continue;
            }

            $depth--;

            if ($depth === 0) {
                return $position;
            }
        }

        throw self::malformed($declaration);
    }

    /**
     * Parses a `name TYPE` field of a STRUCT or UNION. The name may be a
     * double-quoted identifier.
     *
     * @param non-empty-string $field
     * @param non-empty-string $declaration
     *
     * @return array{name: string, type: non-empty-string}
     */
    private static function parseField(string $field, string $declaration): array
    {
        if (str_starts_with($field, '"')) {
            $end = self::findClosingQuote($field, '"', $declaration);

            $name = self::unquote(substr($field, 0, $end + 1), '"', $declaration);
            $type = trim(substr($field, $end + 1));
        } elseif (preg_match('/^(\S+)\s+(.+)$/s', $field, $matches) === 1) {
            $name = $matches[1];
            $type = trim($matches[2]);
        } else {
            throw self::malformed($declaration);
        }

        if ($type === '') {
            throw self::malformed($declaration);
        }

        return ['name' => $name, 'type' => $type];
    }

    /**
     * Splits a comma-separated list at the top level, ignoring commas inside
     * parentheses, brackets and quotes. Each part is trimmed.
     *
     * @param non-empty-string $declaration
     *
     * @return list<non-empty-string>
     */
    private static function splitTopLevel(string $list, string $declaration): array
    {
        $parts    = [];
        $depth    = 0;
        $start    = 0;
        $length   = strlen($list);
        $position = 0;

        while ($position < $length) {
            $character = $list[$position];

            if ($character === "'" || $character === '"') {
                $position = self::findClosingQuote($list, $character, $declaration, $position) + 1;

                continue;
            }

            if ($character === '(' || $character === '[') {
                $depth++;
            } elseif ($character === ')' || $character === ']') {
                $depth--;

                if ($depth < 0) {
                    throw self::malformed($declaration);
                }
            } elseif ($character === ',' && $depth === 0) {
                $parts[] = self::nonEmptyPart(substr($list, $start, $position - $start), $declaration);
                $start   = $position + 1;
            }

            $position++;
        }

        if ($depth !== 0) {
            throw self::malformed($declaration);
        }

        $parts[] = self::nonEmptyPart(substr($list, $start), $declaration);

        return $parts;
    }

    /**
     * Returns the position of the quote that closes the quoted text starting
     * at $start. A doubled quote is an escaped quote.
     *
     * @param non-empty-string $declaration
     */
    private static function findClosingQuote(string $text, string $quote, string $declaration, int $start = 0): int
    {
        $length   = strlen($text);
        $position = $start + 1;

        while ($position < $length) {
            if ($text[$position] !== $quote) {
                $position++;

                continue;
            }

            if ($position + 1 < $length && $text[$position + 1] === $quote) {
                $position += 2;

                continue;
            }

            return $position;
        }

        throw self::malformed($declaration);
    }

    /** @param non-empty-string $declaration */
    private static function unquote(string $text, string $quote, string $declaration): string
    {
        if (strlen($text) < 2 || ! str_ends_with($text, $quote)) {
            throw self::malformed($declaration);
        }

        return str_replace($quote . $quote, $quote, substr($text, 1, -1));
    }

    /**
     * @param non-empty-string $declaration
     *
     * @return non-empty-string
     */
    private static function nonEmptyPart(string $part, string $declaration): string
    {
        $part = trim($part);

        if ($part === '') {
            throw self::malformed($declaration);
        }

        return $part;
    }

    /**
     * @param non-empty-string $declaration
     *
     * @return non-empty-string
     */
    private static function normalizeName(string $name, string $declaration): string
    {
        $name = strtoupper(trim((string) preg_replace('/\s+/', ' ', $name)));

        if ($name === '') {
            throw self::malformed($declaration);
        }

        return $name;
    }

    /**
     * @param non-empty-string                                       $name
     * @param non-empty-string                                       $declaration
     * @param list<int>                                              $arguments
     * @param list<string>                                           $values
     * @param list<array{name: string|null, type: non-empty-string}> $children
     *
     * @return ParsedType
     */
    private static function result(
        string $name,
        string $declaration,
        array $arguments = [],
        array $values = [],
        array $children = [],
    ): array {
        return [
            'name' => $name,
            'declaration' => $declaration,
            'arguments' => $arguments,
            'values' => $values,
            'children' => $children,
        ];
    }

    private static function malformed(string $declaration): InvalidArgumentException
    {
        return new InvalidArgumentException(sprintf('Cannot parse the DuckDB type string "%s".', $declaration));
    }
}
