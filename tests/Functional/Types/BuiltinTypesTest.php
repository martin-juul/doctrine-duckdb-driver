<?php

declare(strict_types=1);

namespace MartinJuul\DBAL\DuckDB\Tests\Functional\Types;

use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Types\Types;
use PHPUnit\Framework\Attributes\DataProvider;

final class BuiltinTypesTest extends TypeTestCase
{
    public function testGuidColumnsStoreUuidAndRoundTrip(): void
    {
        $this->createTable(Column::editor()->setUnquotedName('v')->setTypeName(Types::GUID)->setNotNull(false)->create());

        self::assertSame('UUID', $this->storedType());
        self::assertSame(
            '0b9e2c3a-7f61-4d2e-9a43-5c1d8e6f7a90',
            $this->roundTrip('0b9e2c3a-7f61-4d2e-9a43-5c1d8e6f7a90', Types::GUID),
        );
    }

    public function testJsonColumnsStoreJsonAndRoundTrip(): void
    {
        $this->createTable(Column::editor()->setUnquotedName('v')->setTypeName(Types::JSON)->setNotNull(false)->create());

        self::assertSame('JSON', $this->storedType());
        self::assertSame(
            ['name' => 'x', 'tags' => [1, 2], 'nested' => ['ok' => true]],
            $this->roundTrip(['name' => 'x', 'tags' => [1, 2], 'nested' => ['ok' => true]], Types::JSON),
        );
    }

    public function testDecimalColumnsKeepPrecisionUpTo38(): void
    {
        $this->createTable(
            Column::editor()
                ->setUnquotedName('v')
                ->setTypeName(Types::DECIMAL)
                ->setPrecision(38)
                ->setScale(10)
                ->setNotNull(false)
                ->create(),
        );

        self::assertSame('DECIMAL(38,10)', $this->storedType());
        self::assertSame(
            '1234567890123456789012345678.1234567890',
            $this->roundTrip('1234567890123456789012345678.1234567890', Types::DECIMAL),
        );
    }

    /** @return iterable<string, array{string, string}> */
    public static function dateAndTimeTypes(): iterable
    {
        yield 'date' => [Types::DATE_IMMUTABLE, 'DATE'];
        yield 'datetime' => [Types::DATETIME_IMMUTABLE, 'TIMESTAMP'];
        yield 'datetimetz' => [Types::DATETIMETZ_IMMUTABLE, 'TIMESTAMP WITH TIME ZONE'];
        yield 'time' => [Types::TIME_IMMUTABLE, 'TIME'];
    }

    #[DataProvider('dateAndTimeTypes')]
    public function testDateAndTimeTypesMapToDuckDBTypes(string $typeName, string $duckdbType): void
    {
        $this->createTable(Column::editor()->setUnquotedName('v')->setTypeName($typeName)->create());

        self::assertSame($duckdbType, $this->storedType());
    }
}
