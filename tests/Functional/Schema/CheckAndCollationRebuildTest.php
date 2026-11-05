<?php

declare(strict_types=1);

namespace MartinJuul\DBAL\DuckDB\Tests\Functional\Schema;

use Closure;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Platforms\Exception\NotSupported;
use Doctrine\DBAL\Schema\ColumnEditor;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\SchemaDiff;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use MartinJuul\DBAL\DuckDB\Driver\StatementClassifier;
use MartinJuul\DBAL\DuckDB\Platform\DuckDBPlatform;
use MartinJuul\DBAL\DuckDB\Schema\DuckDBComparator;
use MartinJuul\DBAL\DuckDB\Schema\DuckDBSchemaManager;
use MartinJuul\DBAL\DuckDB\Tests\Functional\FunctionalTestCase;

use function array_map;
use function is_string;

/**
 * A rebuild keeps the CHECK constraints that DBAL cannot represent and the
 * column collations, which introspection does not report, on the tables it
 * changes and on the tables it pulls in because they reference one, or
 * refuses the change.
 */
final class CheckAndCollationRebuildTest extends FunctionalTestCase
{
    private const array P_CHECKS = [
        '(length(code) <= 3)',
        '(lo < hi)',
        '(lo >= 0)',
        "(status IN ('a', 'b'))",
    ];

    private const array C_CHECKS = ["(tag != 'x')"];

    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = self::connect();

        $this->connection->executeStatement(
            "CREATE TABLE p (id INTEGER PRIMARY KEY, status VARCHAR CHECK (status IN ('a', 'b')), "
                . 'code VARCHAR CHECK (length(code) <= 3), lo INTEGER CHECK (lo >= 0), hi INTEGER, '
                . 'label VARCHAR COLLATE NOCASE, note VARCHAR, CHECK (lo < hi))',
        );
        $this->connection->executeStatement('CREATE INDEX p_label ON p (label)');
        $this->connection->executeStatement(
            'CREATE TABLE c (id INTEGER PRIMARY KEY, p_id INTEGER REFERENCES p (id), tag VARCHAR COLLATE NOCASE '
                . "CHECK (tag <> 'x'))",
        );
        $this->connection->executeStatement("INSERT INTO p VALUES (1, 'a', 'abc', 0, 1, 'Alpha', 'n')");
        $this->connection->executeStatement("INSERT INTO c VALUES (1, 1, 'Tag')");

        self::assertSame(self::P_CHECKS, $this->checks('p'));
        self::assertSame(self::C_CHECKS, $this->checks('c'));
        $this->assertConstraintsHold('status', 'hi');
    }

    public function testASchemaDiffKeepsChecksAndCollationsOfTheChangedAndTheReferencingTable(): void
    {
        $this->schemaManager()->alterSchema($this->diff(static fn (Table $p): Table => $p->edit()
            ->renameColumnByUnquotedName('note', 'remark')
            ->create()));

        self::assertSame(self::P_CHECKS, $this->checks('p'));
        self::assertSame(self::C_CHECKS, $this->checks('c'));
        self::assertSame(['n'], $this->connection->fetchFirstColumn('SELECT remark FROM p'));
        $this->assertConstraintsHold('status', 'hi');
    }

    public function testGeneratedSqlKeepsChecksAndCollations(): void
    {
        $diff = $this->diff(static fn (Table $p): Table => $p->edit()
            ->renameColumnByUnquotedName('note', 'remark')
            ->create());

        foreach ($this->platform()->getAlterSchemaSQL($diff) as $sql) {
            $this->connection->executeStatement($sql);
        }

        self::assertSame(self::P_CHECKS, $this->checks('p'));
        self::assertSame(self::C_CHECKS, $this->checks('c'));
        $this->assertConstraintsHold('status', 'hi');
    }

    public function testAlterTableKeepsChecksAndCollations(): void
    {
        $old = $this->schemaManager()->introspectTableByUnquotedName('c');
        $new = $old->edit()
            ->modifyColumnByUnquotedName('p_id', static function (ColumnEditor $column): void {
                $column->setNotNull(true);
            })
            ->create();

        self::assertContains(
            StatementClassifier::REBUILD_BEGIN,
            $this->platform()->getAlterTableSQL($this->comparator()->compareTables($old, $new)),
        );

        $this->schemaManager()->alterTable($this->comparator()->compareTables($old, $new));

        self::assertSame(self::C_CHECKS, $this->checks('c'));
        $this->assertConstraintsHold('status', 'hi');
    }

    public function testRenamingATableKeepsChecksAndCollations(): void
    {
        $this->connection->executeStatement('DROP TABLE c');

        $this->schemaManager()->renameTable('p', 'p2');

        self::assertSame(self::P_CHECKS, $this->checks('p2'));
        $this->expectCheckViolation("INSERT INTO p2 VALUES (2, 'z', 'abc', 0, 1, 'x', 'n')");
        self::assertSame(1, $this->connection->fetchOne("SELECT count(*) FROM p2 WHERE label = 'ALPHA'"));
    }

    public function testRenamingColumnsThatChecksUseRewritesTheChecks(): void
    {
        $this->schemaManager()->alterSchema($this->diff(static fn (Table $p): Table => $p->edit()
            ->renameColumnByUnquotedName('status', 'state')
            ->renameColumnByUnquotedName('hi', 'upper')
            ->renameColumnByUnquotedName('label', 'title')
            ->create()));

        self::assertSame(
            ['(length(code) <= 3)', '(lo < upper)', '(lo >= 0)', "(state IN ('a', 'b'))"],
            $this->checks('p'),
        );
        $this->assertConstraintsHold('state', 'upper', 'title');
    }

    public function testARenameLeavesCastTypesAndStructFieldsOfTheSameName(): void
    {
        $this->connection->executeStatement(
            'CREATE TABLE d (id INTEGER PRIMARY KEY, "varchar" VARCHAR, f VARCHAR, s STRUCT(f VARCHAR), '
                . "CHECK (\"varchar\" <> CAST(1 AS VARCHAR)), CHECK (s.f <> 'x'))",
        );

        $old = $this->schemaManager()->introspectTableByUnquotedName('d');
        $new = $old->edit()->renameColumnByUnquotedName('varchar', 'vc')->create();

        $this->schemaManager()->alterTable($this->comparator()->compareTables($old, $new));

        self::assertSame(["(s.f != 'x')", '(vc != CAST(1 AS VARCHAR))'], $this->checks('d'));
        $this->expectCheckViolation("INSERT INTO d VALUES (1, '1', 'y', {'f': 'y'})");
        $this->expectCheckViolation("INSERT INTO d VALUES (1, '2', 'y', {'f': 'x'})");

        // DuckDB reports the struct field as a column, so a rename of the column of that name is refused.
        $old = $this->schemaManager()->introspectTableByUnquotedName('d');
        $new = $old->edit()->renameColumnByUnquotedName('f', 'g')->create();

        $this->expectException(NotSupported::class);
        $this->expectExceptionMessage('the new name cannot be applied to the expression reliably');

        $this->schemaManager()->alterTable($this->comparator()->compareTables($old, $new));
    }

    public function testDroppingAColumnNamedLikeAStructFieldThatACheckUsesIsRefused(): void
    {
        $this->connection->executeStatement(
            "CREATE TABLE d (id INTEGER PRIMARY KEY, f VARCHAR, s STRUCT(f VARCHAR), g INTEGER, CHECK (s.f <> 'x'))",
        );
        $this->connection->executeStatement('CREATE INDEX d_g ON d (g)');

        $old = $this->schemaManager()->introspectTableByUnquotedName('d');
        $new = $old->edit()->dropColumnByUnquotedName('f')->create();

        $this->expectException(NotSupported::class);
        $this->expectExceptionMessage('uses column f, which this change drops, or a struct field of the same name');

        $this->schemaManager()->alterTable($this->comparator()->compareTables($old, $new));
    }

    public function testDroppingTheOnlyColumnOfACheckDropsTheCheck(): void
    {
        $this->schemaManager()->alterSchema($this->diff(static fn (Table $p): Table => $p->edit()
            ->dropColumnByUnquotedName('code')
            ->create()));

        self::assertSame(['(lo < hi)', '(lo >= 0)', "(status IN ('a', 'b'))"], $this->checks('p'));
        self::assertSame(self::C_CHECKS, $this->checks('c'));
    }

    public function testDroppingOneColumnOfAMultiColumnCheckIsRefusedNamingTheCheck(): void
    {
        $before = $this->snapshot();

        try {
            $this->schemaManager()->alterSchema($this->diff(static fn (Table $p): Table => $p->edit()
                ->dropColumnByUnquotedName('hi')
                ->create()));

            self::fail('The rebuild succeeded.');
        } catch (NotSupported $e) {
            self::assertStringContainsString(
                'Cannot rebuild table p: its CHECK constraint p_lo_hi_check, CHECK ((lo < hi)), uses column hi, '
                    . 'which this change drops',
                $e->getMessage(),
            );
        }

        self::assertSame($before, $this->snapshot());
    }

    public function testChangingTheTypeOfACollatedColumnToANonStringTypeIsRefused(): void
    {
        $before = $this->snapshot();

        try {
            $this->schemaManager()->alterSchema($this->diff(
                static fn (Table $p): Table => $p,
                static fn (Table $c): Table => $c->edit()
                    ->modifyColumnByUnquotedName('tag', static function (ColumnEditor $column): void {
                        $column->setTypeName(Types::INTEGER);
                    })
                    ->renameColumnByUnquotedName('p_id', 'parent_id')
                    ->create(),
            ));

            self::fail('The rebuild succeeded.');
        } catch (NotSupported $e) {
            self::assertStringContainsString(
                'Cannot rebuild table c: its column tag has the collation NOCASE, which the column\'s new type '
                    . 'INTEGER cannot keep',
                $e->getMessage(),
            );
        }

        self::assertSame($before, $this->snapshot());
    }

    public function testARebuildWithoutTheCatalogFailsWhenItWouldDropACheckOrCollation(): void
    {
        $before = $this->snapshot();
        $old    = $this->schemaManager()->introspectTableByUnquotedName('c');
        $new    = $old->edit()->renameColumnByUnquotedName('tag', 'label')->create();

        try {
            foreach ($this->platform()->getAlterTableSQL($this->comparator()->compareTables($old, $new)) as $sql) {
                $this->connection->executeStatement($sql);
            }

            self::fail('The rebuild succeeded.');
        } catch (DriverException $e) {
            self::assertStringContainsString(
                'Cannot rebuild table c without reading DuckDB\'s catalog: it has a generated column, a CHECK '
                    . 'constraint or a collation',
                $e->getMessage(),
            );
        }

        self::assertFalse($this->connection->isTransactionActive());
        self::assertSame($before, $this->snapshot());
    }

    public function testARebuildWithoutTheCatalogRunsWhenTheTableHasOnlyColumnBounds(): void
    {
        $this->connection->executeStatement('CREATE TABLE b (id INTEGER PRIMARY KEY, qty INTEGER CHECK (qty >= 0))');
        $this->connection->executeStatement('INSERT INTO b VALUES (1, 5)');

        $old = $this->schemaManager()->introspectTableByUnquotedName('b');
        $new = $old->edit()->renameColumnByUnquotedName('qty', 'amount')->create();

        foreach ($this->platform()->getAlterTableSQL($this->comparator()->compareTables($old, $new)) as $sql) {
            $this->connection->executeStatement($sql);
        }

        self::assertSame(['(amount >= 0)'], $this->checks('b'));
        self::assertSame([5], $this->connection->fetchFirstColumn('SELECT amount FROM b'));
    }

    /**
     * Each CHECK constraint rejects a row that violates only it, and the
     * collated columns of both tables still compare case-insensitively.
     */
    private function assertConstraintsHold(string $status, string $hi, string $label = 'label'): void
    {
        $insert = 'INSERT INTO p (id, ' . $status . ', code, lo, ' . $hi . ', ' . $label . ') VALUES ';

        $this->expectCheckViolation($insert . "(2, 'z', 'abc', 0, 1, 'x')");
        $this->expectCheckViolation($insert . "(2, 'a', 'abcd', 0, 1, 'x')");
        $this->expectCheckViolation($insert . "(2, 'a', 'abc', 5, 1, 'x')");
        $this->expectCheckViolation($insert . "(2, 'a', 'abc', -5, 1, 'x')");
        $this->expectCheckViolation("INSERT INTO c (id, p_id, tag) VALUES (2, 1, 'X')");

        self::assertSame(1, $this->connection->fetchOne('SELECT count(*) FROM p WHERE ' . $label . " = 'ALPHA'"));
        self::assertSame(1, $this->connection->fetchOne("SELECT count(*) FROM c WHERE tag = 'TAG'"));
        self::assertSame(1, $this->connection->fetchOne('SELECT count(*) FROM p'));
    }

    private function expectCheckViolation(string $sql): void
    {
        try {
            $this->connection->executeStatement($sql);

            self::fail('A CHECK constraint accepted: ' . $sql);
        } catch (DriverException $e) {
            self::assertStringContainsString('CHECK constraint failed', $e->getMessage());
        }
    }

    /**
     * The expressions of the table's CHECK constraints, sorted.
     *
     * @return list<string>
     */
    private function checks(string $table): array
    {
        return array_map(
            static fn (mixed $expression): string => is_string($expression) ? $expression : '',
            $this->connection->fetchFirstColumn(
                "SELECT expression FROM duckdb_constraints() WHERE table_name = ? AND constraint_type = 'CHECK' "
                    . 'ORDER BY expression',
                [$table],
            ),
        );
    }

    /** @return array{tables: list<mixed>, p: list<array<string, mixed>>, c: list<array<string, mixed>>} */
    private function snapshot(): array
    {
        return [
            'tables' => $this->connection->fetchFirstColumn('SELECT sql FROM duckdb_tables() ORDER BY table_name'),
            'p' => $this->connection->fetchAllAssociative('SELECT * FROM p ORDER BY id'),
            'c' => $this->connection->fetchAllAssociative('SELECT * FROM c ORDER BY id'),
        ];
    }

    /**
     * @param Closure(Table): Table $changeP
     * @param Closure(Table): Table $changeC
     */
    private function diff(Closure $changeP, Closure|null $changeC = null): SchemaDiff
    {
        $current   = $this->schemaManager()->introspectSchema();
        $changeC ??= static fn (Table $c): Table => $c;

        return $this->comparator()->compareSchemas(
            $current,
            new Schema([$changeP($current->getTable('p')), $changeC($current->getTable('c'))]),
        );
    }

    private function comparator(): DuckDBComparator
    {
        return $this->schemaManager()->createComparator();
    }

    private function platform(): DuckDBPlatform
    {
        $platform = $this->connection->getDatabasePlatform();
        self::assertInstanceOf(DuckDBPlatform::class, $platform);

        return $platform;
    }

    private function schemaManager(): DuckDBSchemaManager
    {
        $schemaManager = $this->connection->createSchemaManager();
        self::assertInstanceOf(DuckDBSchemaManager::class, $schemaManager);

        return $schemaManager;
    }
}
