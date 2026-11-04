<?php

declare(strict_types=1);

namespace MartinJuul\DBAL\DuckDB\Tests\Functional\Types;

use Doctrine\DBAL\Schema\Column;
use MartinJuul\DBAL\DuckDB\Types\ListType;

final class ListTypeTest extends TypeTestCase
{
    public function testDeclaresTheElementType(): void
    {
        $this->createTable(self::integerList());

        self::assertSame('INTEGER[]', $this->storedType());
    }

    public function testIntegerListsRoundTrip(): void
    {
        $this->createTable(self::integerList());

        self::assertSame([1, 2, 3], $this->roundTrip([1, 2, 3], ListType::NAME));
        self::assertSame([], $this->roundTrip([], ListType::NAME));
        self::assertNull($this->roundTrip(null, ListType::NAME));
    }

    public function testNestedListsRoundTrip(): void
    {
        $this->createTable(self::valueColumn(ListType::NAME, [ListType::ELEMENT_TYPE_OPTION => 'STRUCT(a INTEGER)[]']));

        self::assertSame([[['a' => 1]], [], [['a' => null]]], $this->roundTrip([[['a' => 1]], [], [['a' => null]]], ListType::NAME));
    }

    public function testIntrospectsTheElementTypeWithoutChanges(): void
    {
        $declared = $this->createTable(self::integerList());

        $this->assertIntrospectsUnchanged($declared, [ListType::ELEMENT_TYPE_OPTION => 'INTEGER']);
    }

    private static function integerList(): Column
    {
        return self::valueColumn(ListType::NAME, [ListType::ELEMENT_TYPE_OPTION => 'INTEGER']);
    }
}
