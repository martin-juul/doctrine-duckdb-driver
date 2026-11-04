<?php

declare(strict_types=1);

namespace MartinJuul\DBAL\DuckDB\Tests\Functional\Schema;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\ColumnEditor;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\SchemaDiff;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use MartinJuul\DBAL\DuckDB\Platform\DuckDBPlatform;
use MartinJuul\DBAL\DuckDB\Schema\DuckDBSchemaManager;
use MartinJuul\DBAL\DuckDB\Tests\Functional\FunctionalTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class CheckConstraintIntrospectionTest extends FunctionalTestCase
{
    private Connection $connection;

    private DuckDBPlatform $platform;

    private DuckDBSchemaManager $schemaManager;

    protected function setUp(): void
    {
        $this->connection = self::connect();

        $platform = $this->connection->getDatabasePlatform();
        self::assertInstanceOf(DuckDBPlatform::class, $platform);
        $this->platform = $platform;

        $schemaManager = $this->connection->createSchemaManager();
        self::assertInstanceOf(DuckDBSchemaManager::class, $schemaManager);
        $this->schemaManager = $schemaManager;
    }

    /** @return iterable<string, array{ColumnEditor, int|float|string|null, int|float|string|null}> */
    public static function bounds(): iterable
    {
        $integer = static fn (): ColumnEditor => Column::editor()->setUnquotedName('v')->setTypeName(Types::INTEGER);
        $decimal = static fn (): ColumnEditor => Column::editor()
            ->setUnquotedName('v')
            ->setTypeName(Types::DECIMAL)
            ->setPrecision(10)
            ->setScale(2);

        yield 'integer minimum' => [$integer()->setMinimumValue(1), 1, null];
        yield 'integer maximum' => [$integer()->setMaximumValue(9), null, 9];
        yield 'integer both' => [$integer()->setMinimumValue(-5)->setMaximumValue(9), -5, 9];
        yield 'decimal minimum' => [$decimal()->setMinimumValue(1.5), 1.5, null];
        yield 'decimal maximum' => [$decimal()->setMaximumValue(99), null, 99];
        yield 'decimal both' => [$decimal()->setMinimumValue(0)->setMaximumValue(2.25), 0, 2.25];
    }

    #[DataProvider('bounds')]
    public function testBothIntrospectionPathsReportTheDeclaredBoundsAndTheComparatorSeesNoChange(
        ColumnEditor $column,
        int|float|string|null $minimum,
        int|float|string|null $maximum,
    ): void {
        $declared = new Schema([self::table($column->create())]);

        $this->create($declared);

        $fromSchema = $this->schemaManager->introspectSchema()->getTable('bounded')->getColumn('v');
        $fromTables = $this->legacyTable('bounded')->getColumn('v');

        foreach ([$fromSchema, $fromTables] as $introspected) {
            self::assertEquals($minimum, $introspected->getMinimumValue());
            self::assertEquals($maximum, $introspected->getMaximumValue());
        }

        $comparator = $this->schemaManager->createComparator();

        self::assertSame([], self::tableChanges($comparator->compareSchemas($this->schemaManager->introspectSchema(), $declared)));
        self::assertSame([], self::tableChanges($comparator->compareSchemas($declared, $this->schemaManager->introspectSchema())));
    }

    public function testChecksOtherThanASingleBoundAreNotMapped(): void
    {
        $this->connection->executeStatement(
            'CREATE TABLE odd ('
            . 'a INTEGER CHECK (a != 5), b INTEGER CHECK (b >= 1 AND b <= 9), c INTEGER CHECK (abs(c) <= 3), '
            . 'd INTEGER, e INTEGER, CHECK (d >= e), "Q" INTEGER CHECK ("Q" >= 7))',
        );

        $table = $this->legacyTable('odd');

        foreach (['a', 'b', 'c', 'd', 'e'] as $name) {
            self::assertNull($table->getColumn($name)->getMinimumValue(), $name);
            self::assertNull($table->getColumn($name)->getMaximumValue(), $name);
        }

        self::assertSame(7, $table->getColumn('Q')->getMinimumValue());
        self::assertNull($table->getColumn('Q')->getMaximumValue());
    }

    public function testDroppingADeclaredMaximumRebuildsTheTableAndTheCheckIsGone(): void
    {
        $with    = new Schema([self::table(self::bounded()->setMinimumValue(1)->setMaximumValue(9)->create())]);
        $without = new Schema([self::table(self::bounded()->setMinimumValue(1)->create())]);

        $this->create($with);
        $this->connection->executeStatement('INSERT INTO bounded (v) VALUES (3)');

        $diff = $this->schemaManager->createComparator()->compareSchemas($this->schemaManager->introspectSchema(), $without);

        self::assertCount(1, $diff->getAlteredTables());

        foreach ($this->platform->getAlterSchemaSQL($diff) as $sql) {
            $this->connection->executeStatement($sql);
        }

        $column = $this->legacyTable('bounded')->getColumn('v');

        self::assertSame(1, $column->getMinimumValue());
        self::assertNull($column->getMaximumValue());
        self::assertEquals([3], $this->connection->fetchFirstColumn('SELECT v FROM bounded'));

        $this->connection->executeStatement('INSERT INTO bounded (v) VALUES (100)');
        self::assertSame(
            [],
            self::tableChanges($this->schemaManager->createComparator()->compareSchemas($this->schemaManager->introspectSchema(), $without)),
        );
    }

    private function legacyTable(string $name): Table
    {
        foreach ($this->schemaManager->introspectTables() as $table) {
            if ($table->getObjectName()->getUnqualifiedName()->getValue() === $name) {
                return $table;
            }
        }

        self::fail('Table ' . $name . ' was not introspected.');
    }

    /** @return list<string> */
    private static function tableChanges(SchemaDiff $diff): array
    {
        $names = [];

        foreach ([...$diff->getCreatedTables(), ...$diff->getDroppedTables()] as $table) {
            $names[] = $table->getName();
        }

        foreach ($diff->getAlteredTables() as $tableDiff) {
            $names[] = $tableDiff->getOldTable()->getName();
        }

        return $names;
    }

    private static function bounded(): ColumnEditor
    {
        return Column::editor()->setUnquotedName('v')->setTypeName(Types::INTEGER);
    }

    private static function table(Column $column): Table
    {
        return Table::editor()->setUnquotedName('bounded')->setColumns($column)->create();
    }

    private function create(Schema $schema): void
    {
        foreach ($this->platform->getCreateTablesSQL($schema->getTables()) as $sql) {
            $this->connection->executeStatement($sql);
        }
    }
}
