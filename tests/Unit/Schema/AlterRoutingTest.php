<?php

declare(strict_types=1);

namespace MartinJuul\DBAL\DuckDB\Tests\Unit\Schema;

use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\ComparatorConfig;
use Doctrine\DBAL\Schema\DefaultExpression\CurrentDate;
use Doctrine\DBAL\Schema\DefaultExpression\CurrentTimestamp;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Schema\TableDiff;
use Doctrine\DBAL\Types\Types;
use MartinJuul\DBAL\DuckDB\Platform\DuckDBPlatform;
use MartinJuul\DBAL\DuckDB\Schema\AlterRouting;
use MartinJuul\DBAL\DuckDB\Schema\DuckDBComparator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_keys;
use function array_map;

final class AlterRoutingTest extends TestCase
{
    /** @return iterable<string, array{Table, Table, bool, list<string>|null}> */
    public static function changes(): iterable
    {
        $nullable = Column::editor()->setUnquotedName('extra')->setTypeName(Types::STRING)->setNotNull(false)->create();

        yield 'nullable column on an indexed table' => [
            self::table(indexed: true),
            self::table(indexed: true, extra: $nullable),
            false,
            ['ALTER TABLE "t" ADD COLUMN "extra" VARCHAR DEFAULT NULL'],
        ];

        $withDefault = Column::editor()
            ->setUnquotedName('extra')
            ->setTypeName(Types::INTEGER)
            ->setDefaultValue(7)
            ->setComment('Seven')
            ->create();

        yield 'NOT NULL column with a default on a plain table' => [
            self::table(),
            self::table(extra: $withDefault),
            false,
            ['ALTER TABLE "t" ADD COLUMN "extra" INTEGER DEFAULT 7 NOT NULL', 'COMMENT ON COLUMN "t"."extra" IS \'Seven\''],
        ];

        yield 'NOT NULL column with a default on an indexed table' => [
            self::table(indexed: true),
            self::table(indexed: true, extra: $withDefault),
            false,
            null,
        ];

        yield 'NOT NULL column with a default on a referenced table' => [
            self::table(),
            self::table(extra: $withDefault),
            true,
            null,
        ];

        yield 'NOT NULL column with a CURRENT_TIMESTAMP default' => [
            self::table(),
            self::table(extra: Column::editor()
                ->setUnquotedName('extra')
                ->setTypeName(Types::DATETIME_IMMUTABLE)
                ->setDefaultValue(new CurrentTimestamp())
                ->create()),
            false,
            null,
        ];

        yield 'NOT NULL column with a CURRENT_DATE default' => [
            self::table(),
            self::table(extra: Column::editor()
                ->setUnquotedName('extra')
                ->setTypeName(Types::DATE_IMMUTABLE)
                ->setDefaultValue(new CurrentDate())
                ->create()),
            false,
            null,
        ];

        yield 'nullable column with a CURRENT_TIMESTAMP default' => [
            self::table(indexed: true),
            self::table(indexed: true, extra: Column::editor()
                ->setUnquotedName('extra')
                ->setTypeName(Types::DATETIME_IMMUTABLE)
                ->setDefaultValue(new CurrentTimestamp())
                ->setNotNull(false)
                ->create()),
            false,
            ['ALTER TABLE "t" ADD COLUMN "extra" TIMESTAMP_S DEFAULT CURRENT_TIMESTAMP'],
        ];

        yield 'NOT NULL column with a negative numeric default' => [
            self::table(),
            self::table(extra: Column::editor()
                ->setUnquotedName('extra')
                ->setTypeName(Types::FLOAT)
                ->setDefaultValue(-1.5)
                ->create()),
            false,
            ['ALTER TABLE "t" ADD COLUMN "extra" DOUBLE DEFAULT -1.5 NOT NULL'],
        ];

        yield 'NOT NULL column without a default' => [
            self::table(),
            self::table(extra: Column::editor()->setUnquotedName('extra')->setTypeName(Types::INTEGER)->create()),
            false,
            null,
        ];

        yield 'autoincrement column' => [
            self::table(),
            self::table(extra: Column::editor()
                ->setUnquotedName('extra')
                ->setTypeName(Types::INTEGER)
                ->setNotNull(false)
                ->setAutoincrement(true)
                ->create()),
            false,
            null,
        ];

        yield 'type change on a plain table' => [
            self::table(),
            self::table(valueType: Types::BIGINT),
            false,
            ['ALTER TABLE "t" ALTER COLUMN "v" TYPE BIGINT'],
        ];

        yield 'type change on an indexed table' => [
            self::table(indexed: true),
            self::table(indexed: true, valueType: Types::BIGINT),
            false,
            null,
        ];

        yield 'default set and dropped on an indexed table' => [
            self::table(indexed: true, valueDefault: 1),
            self::table(indexed: true, valueDefault: 2),
            false,
            ['ALTER TABLE "t" ALTER COLUMN "v" SET DEFAULT 2'],
        ];

        yield 'default dropped on a referenced table' => [
            self::table(valueDefault: 1),
            self::table(),
            true,
            ['ALTER TABLE "t" ALTER COLUMN "v" DROP DEFAULT'],
        ];

        yield 'NOT NULL set on a plain table' => [
            self::table(valueNotNull: false),
            self::table(),
            false,
            ['ALTER TABLE "t" ALTER COLUMN "v" SET NOT NULL'],
        ];

        yield 'NOT NULL set on an indexed table' => [
            self::table(indexed: true, valueNotNull: false),
            self::table(indexed: true),
            false,
            null,
        ];

        yield 'NOT NULL dropped' => [self::table(), self::table(valueNotNull: false), false, null];

        yield 'comment changed on an indexed table' => [
            self::table(indexed: true),
            self::table(indexed: true, valueComment: 'Value'),
            false,
            ['COMMENT ON COLUMN "t"."v" IS \'Value\''],
        ];

        yield 'column renamed' => [self::table(), self::table(valueName: 'w'), false, null];

        yield 'column dropped from a plain table' => [
            self::table(extra: $nullable),
            self::table(),
            false,
            ['ALTER TABLE "t" DROP COLUMN "extra"'],
        ];

        yield 'column dropped from a table that owns a foreign key' => [
            self::table(extra: $nullable, foreignKey: true),
            self::table(foreignKey: true),
            false,
            null,
        ];

        yield 'foreign key added' => [self::table(), self::table(foreignKey: true), false, null];

        yield 'foreign key dropped' => [self::table(foreignKey: true), self::table(), false, null];

        yield 'index added' => [
            self::table(),
            self::table(indexed: true),
            false,
            ['CREATE INDEX idx_t_v ON "t" (v)'],
        ];

        yield 'index dropped' => [self::table(indexed: true), self::table(), false, ['DROP INDEX "idx_t_v"']];

        yield 'primary key added to a plain table' => [
            self::table(primaryKey: false),
            self::table(),
            false,
            ['ALTER TABLE "t" ADD PRIMARY KEY (id)'],
        ];

        yield 'primary key dropped' => [self::table(), self::table(primaryKey: false), false, null];

        yield 'autoincrement switched on' => [self::table(), self::table(autoincrement: true), false, null];

        yield 'CHECK constraint dropped' => [self::table(minimum: 0), self::table(), false, null];
    }

    /** @param list<string>|null $expected */
    #[DataProvider('changes')]
    public function testRoutesEachChange(Table $old, Table $new, bool $referenced, array|null $expected): void
    {
        $platform = new DuckDBPlatform();

        self::assertSame($expected, (new AlterRouting($platform))->nativeSQL(self::diff($old, $new), $referenced));
    }

    public function testDroppingAUniqueIndexThatIsAConstraintRebuilds(): void
    {
        $platform = new DuckDBPlatform();
        $old      = self::table(uniqueValue: true);
        $diff     = self::diff($old, self::table());

        self::assertSame(['DROP INDEX "uniq_t_v"'], (new AlterRouting($platform))->nativeSQL($diff, false));
        self::assertNull((new AlterRouting($platform))->nativeSQL($diff, false, ['v']));
    }

    public function testWithoutAComparisonARebuildCoversTheTableAppliedWithItsDiff(): void
    {
        $platform = new DuckDBPlatform();
        $plan     = (new AlterRouting($platform))->plan([
            self::diff(self::table(indexed: true), self::table(
                indexed: true,
                valueName: 'w',
            )),
        ]);

        self::assertSame([], $plan->nativeSQL);
        self::assertSame(['t'], array_keys($plan->rebuilds));

        $rebuild = $plan->rebuilds['t'];

        self::assertSame(['id', 'w'], array_map(
            static fn (Column $column): string => $column->getObjectName()->toString(),
            $rebuild['new']->getColumns(),
        ));
        self::assertSame([['id', 'id'], ['v', 'w']], array_map(
            static fn (array $pair): array => [
                $pair[0]->getObjectName()->toString(),
                $pair[1]->getObjectName()->toString(),
            ],
            $rebuild['columns'],
        ));
    }

    private static function diff(Table $old, Table $new): TableDiff
    {
        $comparator = new DuckDBComparator(
            new DuckDBPlatform(),
            (new ComparatorConfig())->withReportModifiedIndexes(false),
        );

        return $comparator->compareTables($old, $new);
    }

    /** @param non-empty-string $valueName */
    private static function table(
        bool $indexed = false,
        Column|null $extra = null,
        string $valueName = 'v',
        string $valueType = Types::INTEGER,
        int|null $valueDefault = null,
        bool $valueNotNull = true,
        string $valueComment = '',
        bool $foreignKey = false,
        bool $primaryKey = true,
        bool $autoincrement = false,
        int|null $minimum = null,
        bool $uniqueValue = false,
    ): Table {
        $value = Column::editor()
            ->setUnquotedName($valueName)
            ->setTypeName($valueType)
            ->setDefaultValue($valueDefault)
            ->setNotNull($valueNotNull)
            ->setComment($valueComment)
            ->setMinimumValue($minimum);

        $columns = [
            Column::editor()->setUnquotedName('id')->setTypeName(Types::INTEGER)->setAutoincrement($autoincrement)->create(),
            $value->create(),
        ];

        if ($extra !== null) {
            $columns[] = $extra;
        }

        $editor = Table::editor()->setUnquotedName('t')->setColumns(...$columns);

        if ($primaryKey) {
            $editor->setPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('id')->create());
        }

        if ($indexed) {
            $editor->addIndex(Index::editor()->setUnquotedName('idx_t_v')->setUnquotedColumnNames($valueName)->create());
        }

        if ($uniqueValue) {
            $editor->addIndex(Index::editor()
                ->setUnquotedName('uniq_t_v')
                ->setType(Index\IndexType::UNIQUE)
                ->setUnquotedColumnNames($valueName)
                ->create());
        }

        if ($foreignKey) {
            $editor->addForeignKeyConstraint(
                ForeignKeyConstraint::editor()
                    ->setUnquotedName('fk_t_parent')
                    ->setUnquotedReferencingColumnNames($valueName)
                    ->setUnquotedReferencedTableName('parent')
                    ->setUnquotedReferencedColumnNames('id')
                    ->create(),
            );
        }

        return $editor->create();
    }
}
