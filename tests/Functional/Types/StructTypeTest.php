<?php

declare(strict_types=1);

namespace MartinJuul\DBAL\DuckDB\Tests\Functional\Types;

use Doctrine\DBAL\Schema\Column;
use MartinJuul\DBAL\DuckDB\Types\StructType;

final class StructTypeTest extends TypeTestCase
{
    public function testDeclaresTheFieldsInOrder(): void
    {
        $this->createTable(self::record());

        self::assertSame('STRUCT(a INTEGER, b VARCHAR)', $this->storedType());
    }

    public function testStructsRoundTrip(): void
    {
        $this->createTable(self::record());

        self::assertSame(['a' => 1, 'b' => 'x'], $this->roundTrip(['a' => 1, 'b' => 'x'], StructType::NAME));
        self::assertNull($this->roundTrip(null, StructType::NAME));
    }

    public function testFieldsNeedingQuotesAndNestedTypesRoundTrip(): void
    {
        $declared = $this->createTable(self::valueColumn(StructType::NAME, [
            StructType::FIELDS_OPTION => [
                'my field' => 'INTEGER',
                'Upper' => 'VARCHAR[]',
                'inner' => 'STRUCT(c DOUBLE)',
            ],
        ]));

        self::assertSame(
            ['my field' => 7, 'Upper' => ['p', 'q'], 'inner' => ['c' => 1.5]],
            $this->roundTrip(['my field' => 7, 'Upper' => ['p', 'q'], 'inner' => ['c' => 1.5]], StructType::NAME),
        );

        $this->assertIntrospectsUnchanged($declared, [
            StructType::FIELDS_OPTION => [
                'my field' => 'INTEGER',
                'Upper' => 'VARCHAR[]',
                'inner' => 'STRUCT(c DOUBLE)',
            ],
        ]);
    }

    public function testIntrospectsTheFieldsWithoutChanges(): void
    {
        $declared = $this->createTable(self::record());

        $this->assertIntrospectsUnchanged($declared, [StructType::FIELDS_OPTION => ['a' => 'INTEGER', 'b' => 'VARCHAR']]);
    }

    private static function record(): Column
    {
        return self::valueColumn(StructType::NAME, [StructType::FIELDS_OPTION => ['a' => 'INTEGER', 'b' => 'VARCHAR']]);
    }
}
