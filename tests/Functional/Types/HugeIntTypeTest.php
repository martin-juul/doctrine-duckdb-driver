<?php

declare(strict_types=1);

namespace MartinJuul\DBAL\DuckDB\Tests\Functional\Types;

use MartinJuul\DBAL\DuckDB\Types\HugeIntType;

final class HugeIntTypeTest extends TypeTestCase
{
    public function testValuesRoundTripAsStrings(): void
    {
        $this->createTable(self::valueColumn(HugeIntType::NAME));

        self::assertSame('HUGEINT', $this->storedType());
        self::assertSame(
            '170141183460469231731687303715884105727',
            $this->roundTrip('170141183460469231731687303715884105727', HugeIntType::NAME),
        );
        self::assertSame(
            '-170141183460469231731687303715884105728',
            $this->roundTrip('-170141183460469231731687303715884105728', HugeIntType::NAME),
        );
        self::assertSame('42', $this->roundTrip(42, HugeIntType::NAME));
        self::assertNull($this->roundTrip(null, HugeIntType::NAME));
    }

    public function testIntrospectsWithoutChanges(): void
    {
        $declared = $this->createTable(self::valueColumn(HugeIntType::NAME));

        $this->assertIntrospectsUnchanged($declared, []);
    }
}
