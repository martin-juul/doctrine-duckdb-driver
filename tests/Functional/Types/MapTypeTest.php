<?php

declare(strict_types=1);

namespace MartinJuul\DBAL\DuckDB\Tests\Functional\Types;

use MartinJuul\DBAL\DuckDB\Types\MapType;

final class MapTypeTest extends TypeTestCase
{
    public function testVarcharKeysRoundTrip(): void
    {
        $this->createTable(self::valueColumn(MapType::NAME, [
            MapType::KEY_TYPE_OPTION => 'VARCHAR',
            MapType::VALUE_TYPE_OPTION => 'INTEGER',
        ]));

        self::assertSame('MAP(VARCHAR, INTEGER)', $this->storedType());
        self::assertSame(['k' => 1], $this->roundTrip(['k' => 1], MapType::NAME));
        self::assertSame([], $this->roundTrip([], MapType::NAME));
        self::assertNull($this->roundTrip(null, MapType::NAME));
    }

    public function testSequentialIntegerKeysAreWrittenAsAMap(): void
    {
        $this->createTable(self::valueColumn(MapType::NAME, [
            MapType::KEY_TYPE_OPTION => 'INTEGER',
            MapType::VALUE_TYPE_OPTION => 'VARCHAR',
        ]));

        self::assertSame([0 => 'z', 1 => 'y'], $this->roundTrip([0 => 'z', 1 => 'y'], MapType::NAME));
        self::assertSame('[0, 1]', $this->connection->fetchOne('SELECT CAST(map_keys(v) AS VARCHAR) FROM t'));
    }

    public function testNestedValuesRoundTrip(): void
    {
        $this->createTable(self::valueColumn(MapType::NAME, [
            MapType::KEY_TYPE_OPTION => 'VARCHAR',
            MapType::VALUE_TYPE_OPTION => 'MAP(VARCHAR, INTEGER[])',
        ]));

        self::assertSame(
            ['x' => ['p' => [1, 2]], 'y' => ['q' => [], 'r' => [3]]],
            $this->roundTrip(['x' => ['p' => [1, 2]], 'y' => ['q' => [], 'r' => [3]]], MapType::NAME),
        );
    }

    public function testIntrospectsTheKeyAndValueTypesWithoutChanges(): void
    {
        $declared = $this->createTable(self::valueColumn(MapType::NAME, [
            MapType::KEY_TYPE_OPTION => 'VARCHAR',
            MapType::VALUE_TYPE_OPTION => 'STRUCT(a INTEGER, b VARCHAR[])',
        ]));

        $this->assertIntrospectsUnchanged($declared, [
            MapType::KEY_TYPE_OPTION => 'VARCHAR',
            MapType::VALUE_TYPE_OPTION => 'STRUCT(a INTEGER, b VARCHAR[])',
        ]);
    }
}
