<?php

declare(strict_types=1);

namespace MartinJuul\DBAL\DuckDB\Tests\Unit\Schema;

use InvalidArgumentException;
use MartinJuul\DBAL\DuckDB\Schema\TypeStringParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** @phpstan-import-type ParsedType from TypeStringParser */
final class TypeStringParserTest extends TestCase
{
    /** @return iterable<string, array{string, ParsedType}> */
    public static function typeStrings(): iterable
    {
        yield 'plain type' => [
            'INTEGER',
            self::parsed('INTEGER', 'INTEGER'),
        ];

        yield 'multi-word type' => [
            'TIMESTAMP WITH TIME ZONE',
            self::parsed('TIMESTAMP WITH TIME ZONE', 'TIMESTAMP WITH TIME ZONE'),
        ];

        yield 'lower-case name is upper-cased, declaration is kept' => [
            'varchar',
            self::parsed('VARCHAR', 'varchar'),
        ];

        yield 'decimal' => [
            'DECIMAL(18,4)',
            self::parsed('DECIMAL', 'DECIMAL(18,4)', arguments: [18, 4]),
        ];

        yield 'list' => [
            'INTEGER[]',
            self::parsed('LIST', 'INTEGER[]', children: [['name' => null, 'type' => 'INTEGER']]),
        ];

        yield 'fixed-size array' => [
            'INTEGER[3]',
            self::parsed('ARRAY', 'INTEGER[3]', arguments: [3], children: [['name' => null, 'type' => 'INTEGER']]),
        ];

        yield 'list of lists' => [
            'VARCHAR[][]',
            self::parsed('LIST', 'VARCHAR[][]', children: [['name' => null, 'type' => 'VARCHAR[]']]),
        ];

        yield 'struct' => [
            'STRUCT(a INTEGER, b VARCHAR)',
            self::parsed('STRUCT', 'STRUCT(a INTEGER, b VARCHAR)', children: [
                ['name' => 'a', 'type' => 'INTEGER'],
                ['name' => 'b', 'type' => 'VARCHAR'],
            ]),
        ];

        yield 'struct with quoted field names' => [
            'STRUCT("b c" INTEGER, "x""y" DOUBLE)',
            self::parsed('STRUCT', 'STRUCT("b c" INTEGER, "x""y" DOUBLE)', children: [
                ['name' => 'b c', 'type' => 'INTEGER'],
                ['name' => 'x"y', 'type' => 'DOUBLE'],
            ]),
        ];

        yield 'map' => [
            'MAP(VARCHAR, INTEGER)',
            self::parsed('MAP', 'MAP(VARCHAR, INTEGER)', children: [
                ['name' => null, 'type' => 'VARCHAR'],
                ['name' => null, 'type' => 'INTEGER'],
            ]),
        ];

        yield 'enum' => [
            "ENUM('a', 'it''s', 'x, y')",
            self::parsed('ENUM', "ENUM('a', 'it''s', 'x, y')", values: ['a', "it's", 'x, y']),
        ];

        yield 'union' => [
            'UNION(n INTEGER, s VARCHAR)',
            self::parsed('UNION', 'UNION(n INTEGER, s VARCHAR)', children: [
                ['name' => 'n', 'type' => 'INTEGER'],
                ['name' => 's', 'type' => 'VARCHAR'],
            ]),
        ];

        yield 'array of nested structs' => [
            'STRUCT(a INTEGER[], "b""q" MAP(VARCHAR, STRUCT(x INTEGER)))[2]',
            self::parsed(
                'ARRAY',
                'STRUCT(a INTEGER[], "b""q" MAP(VARCHAR, STRUCT(x INTEGER)))[2]',
                arguments: [2],
                children: [['name' => null, 'type' => 'STRUCT(a INTEGER[], "b""q" MAP(VARCHAR, STRUCT(x INTEGER)))']],
            ),
        ];

        yield 'map of lists' => [
            'MAP(VARCHAR[], DECIMAL(10,2)[3])',
            self::parsed('MAP', 'MAP(VARCHAR[], DECIMAL(10,2)[3])', children: [
                ['name' => null, 'type' => 'VARCHAR[]'],
                ['name' => null, 'type' => 'DECIMAL(10,2)[3]'],
            ]),
        ];
    }

    /** @param ParsedType $expected */
    #[DataProvider('typeStrings')]
    public function testParse(string $type, array $expected): void
    {
        self::assertSame($expected, TypeStringParser::parse($type));
    }

    public function testNestedChildrenParseRecursively(): void
    {
        $struct = TypeStringParser::parse('STRUCT(a INTEGER[], "b""q" MAP(VARCHAR, STRUCT(x INTEGER)))[2]')['children'][0];
        $fields = TypeStringParser::parse($struct['type'])['children'];
        $map    = TypeStringParser::parse($fields[1]['type']);

        self::assertSame('b"q', $fields[1]['name']);
        self::assertSame('MAP', $map['name']);
        self::assertSame('STRUCT', TypeStringParser::parse($map['children'][1]['type'])['name']);
    }

    /** @return iterable<string, array{string}> */
    public static function malformedTypeStrings(): iterable
    {
        yield 'empty' => [''];
        yield 'unclosed parenthesis' => ['STRUCT(a INTEGER'];
        yield 'non-numeric array size' => ['INTEGER[x]'];
    }

    #[DataProvider('malformedTypeStrings')]
    public function testMalformedTypeStringsAreRejected(string $type): void
    {
        $this->expectException(InvalidArgumentException::class);

        TypeStringParser::parse($type);
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
    private static function parsed(
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
}
