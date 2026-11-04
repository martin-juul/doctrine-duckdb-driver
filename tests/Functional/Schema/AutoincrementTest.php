<?php

declare(strict_types=1);

namespace MartinJuul\DBAL\DuckDB\Tests\Functional\Schema;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DatabaseObjectNotFoundException;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\SchemaDiff;
use Doctrine\DBAL\Schema\Sequence;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use MartinJuul\DBAL\DuckDB\Platform\DuckDBPlatform;
use MartinJuul\DBAL\DuckDB\Schema\DuckDBSchemaManager;
use MartinJuul\DBAL\DuckDB\Tests\Functional\FunctionalTestCase;

use function array_map;
use function sort;

final class AutoincrementTest extends FunctionalTestCase
{
    private Connection $connection;

    private DuckDBPlatform $platform;

    private DuckDBSchemaManager $schemaManager;

    protected function setUp(): void
    {
        $this->connection = self::connect();

        $platform = $this->connection->getDatabasePlatform();
        self::assertInstanceOf(DuckDBPlatform::class, $platform);

        $this->platform      = $platform;
        $this->schemaManager = $platform->createSchemaManager($this->connection);
    }

    public function testInsertsWithoutAnIdReceiveIdsFromTheOwnedSequence(): void
    {
        $this->schemaManager->createTable(self::ticketTable());

        $this->connection->insert('ticket', ['title' => 'first']);

        self::assertSame(1, $this->connection->fetchOne("SELECT id FROM ticket WHERE title = 'first'"));

        $returned = $this->connection->fetchFirstColumn(
            "INSERT INTO ticket (title) VALUES ('second'), ('third') RETURNING id",
        );

        self::assertSame([2, 3], $returned);
        self::assertSame([1, 2, 3], $this->connection->fetchFirstColumn('SELECT id FROM ticket ORDER BY id'));

        try {
            $this->connection->lastInsertId();
            self::fail('lastInsertId() returned instead of failing.');
        } catch (DriverException $e) {
            self::assertStringContainsString('RETURNING', $e->getMessage());
        }
    }

    public function testCreateTableDeclaresTheOwnedSequenceBeforeTheTable(): void
    {
        $sql = $this->platform->getCreateTableSQL(self::ticketTable());

        self::assertSame('CREATE SEQUENCE "ticket_id' . DuckDBPlatform::OWNED_SEQUENCE_SUFFIX . '"', $sql[0]);
        self::assertStringStartsWith('CREATE TABLE ticket (', $sql[1]);
        self::assertStringContainsString(
            'id INTEGER DEFAULT nextval(\'"ticket_id' . DuckDBPlatform::OWNED_SEQUENCE_SUFFIX . '"\') NOT NULL',
            $sql[1],
        );
    }

    public function testDroppingAndCreatingTheTableTwiceSucceeds(): void
    {
        $this->dropAndCreateTable(self::ticketTable());

        $this->connection->insert('ticket', ['title' => 'first']);

        $this->dropAndCreateTable(self::ticketTable());

        $this->connection->insert('ticket', ['title' => 'again']);

        self::assertSame([1], $this->connection->fetchFirstColumn('SELECT id FROM ticket'));
        self::assertSame(['ticket_id' . DuckDBPlatform::OWNED_SEQUENCE_SUFFIX], $this->allSequenceNames());
    }

    public function testNativelyRenamedTableKeepsItsSequenceAndDropsIt(): void
    {
        $this->schemaManager->createTable(self::ticketTable());

        $this->connection->insert('ticket', ['title' => 'first']);
        $this->connection->insert('ticket', ['title' => 'second']);

        $this->connection->executeStatement('ALTER TABLE ticket RENAME TO archived_ticket');

        $returned = $this->connection->fetchFirstColumn(
            "INSERT INTO archived_ticket (title) VALUES ('third') RETURNING id",
        );

        self::assertSame([3], $returned);

        // DuckDB cannot rename sequences, so the sequence keeps the old table's name.
        self::assertSame(['ticket_id' . DuckDBPlatform::OWNED_SEQUENCE_SUFFIX], $this->allSequenceNames());

        foreach ([$this->schemaManager->introspectTableByUnquotedName('archived_ticket'), $this->legacyTable('archived_ticket')] as $table) {
            $column = $table->getColumn('id');

            self::assertTrue($column->getAutoincrement());
            self::assertNull($column->getDefault());
        }

        foreach ($this->platform->getDropTablesSQL([$this->schemaManager->introspectTableByUnquotedName('archived_ticket')]) as $sql) {
            $this->connection->executeStatement($sql);
        }

        self::assertSame([], $this->allSequenceNames());
    }

    public function testDropTableDropsTheSequenceOfARenamedTable(): void
    {
        $this->schemaManager->createTable(self::ticketTable());

        $this->connection->executeStatement('ALTER TABLE ticket RENAME TO archived_ticket');

        $this->schemaManager->dropTable('archived_ticket');

        self::assertSame([], $this->allSequenceNames());
    }

    public function testOrmSequenceCoexistsWithTheOwnedSequenceOfTheSameTable(): void
    {
        $this->schemaManager->createTable(self::ticketTable());
        $this->schemaManager->createSequence(self::ormSequence());

        $this->connection->insert('ticket', ['title' => 'first']);

        self::assertSame(1, $this->connection->fetchOne("SELECT id FROM ticket WHERE title = 'first'"));
        self::assertSame(1, $this->connection->fetchOne($this->platform->getSequenceNextValSQL('ticket_id_seq')));
        self::assertSame(
            ['ticket_id' . DuckDBPlatform::OWNED_SEQUENCE_SUFFIX, 'ticket_id_seq'],
            $this->allSequenceNames(),
        );
    }

    public function testOwnedSequencesAreHiddenFromTheSequenceList(): void
    {
        $this->schemaManager->createTable(self::ticketTable());
        $this->schemaManager->createSequence(self::ormSequence());

        self::assertSame(['ticket_id_seq'], self::sequenceNames($this->schemaManager->introspectSequences()));
        self::assertSame(['ticket_id_seq'], self::sequenceNames($this->schemaManager->listSequences()));
    }

    public function testCreatedSchemaReDiffsWithoutChangesThroughTheLegacyIntrospection(): void
    {
        $declared = self::declaredSchema();

        $this->create($declared);

        $introspected = $this->schemaManager->introspectSchema();

        self::assertSame([], self::describe($this->compare($declared, $introspected)));
        self::assertSame([], self::describe($this->compare($introspected, $declared)));
    }

    public function testCreatedSchemaReDiffsWithoutChangesThroughTheMetadataProvider(): void
    {
        $declared = self::declaredSchema();

        $this->create($declared);

        $introspected = Schema::editor()
            ->setDefaultNamespace('main')
            ->setTables(...$this->schemaManager->introspectTables())
            ->setSequences(...$this->schemaManager->introspectSequences())
            ->create();

        self::assertTrue($this->compare($declared, $introspected)->isEmpty());
        self::assertTrue($this->compare($introspected, $declared)->isEmpty());
    }

    private function dropAndCreateTable(Table $table): void
    {
        try {
            $this->schemaManager->dropTable($table->getObjectName()->toSQL($this->platform));
        } catch (DatabaseObjectNotFoundException) {
        }

        $this->schemaManager->createTable($table);
    }

    /** @param non-empty-string $name */
    private function legacyTable(string $name): Table
    {
        foreach ($this->schemaManager->introspectSchema()->getTables() as $table) {
            if ($table->getObjectName()->getUnqualifiedName()->getValue() === $name) {
                return $table;
            }
        }

        self::fail('introspectSchema() did not report the table ' . $name . '.');
    }

    /** @return list<string> */
    private function allSequenceNames(): array
    {
        /** @var list<string> $names */
        $names = $this->connection->fetchFirstColumn('SELECT sequence_name FROM duckdb_sequences() ORDER BY sequence_name');

        return $names;
    }

    private function create(Schema $schema): void
    {
        $statements = $this->platform->getCreateTablesSQL($schema->getTables());

        foreach ($schema->getSequences() as $sequence) {
            $statements[] = $this->platform->getCreateSequenceSQL($sequence);
        }

        foreach ($statements as $sql) {
            $this->connection->executeStatement($sql);
        }
    }

    private function compare(Schema $from, Schema $to): SchemaDiff
    {
        return $this->schemaManager->createComparator()->compareSchemas($from, $to);
    }

    /**
     * The table and sequence differences of a schema diff. The default `main` namespace, which the legacy
     * introspection reports, is not a difference.
     *
     * @return list<string>
     */
    private static function describe(SchemaDiff $diff): array
    {
        $differences = [];

        foreach ($diff->getCreatedTables() as $table) {
            $differences[] = 'created table ' . $table->getObjectName()->toString();
        }

        foreach ($diff->getDroppedTables() as $table) {
            $differences[] = 'dropped table ' . $table->getObjectName()->toString();
        }

        foreach ($diff->getAlteredTables() as $tableDiff) {
            $differences[] = 'altered table ' . $tableDiff->getOldTable()->getObjectName()->toString();
        }

        foreach ($diff->getCreatedSequences() as $sequence) {
            $differences[] = 'created sequence ' . $sequence->getObjectName()->toString();
        }

        foreach ($diff->getDroppedSequences() as $sequence) {
            $differences[] = 'dropped sequence ' . $sequence->getObjectName()->toString();
        }

        foreach ($diff->getAlteredSequences() as $sequence) {
            $differences[] = 'altered sequence ' . $sequence->getObjectName()->toString();
        }

        return $differences;
    }

    private static function declaredSchema(): Schema
    {
        return Schema::editor()
            ->setTables(self::ticketTable())
            ->setSequences(self::ormSequence())
            ->create();
    }

    private static function ticketTable(): Table
    {
        return Table::editor()
            ->setUnquotedName('ticket')
            ->setColumns(
                Column::editor()
                    ->setUnquotedName('id')
                    ->setTypeName(Types::INTEGER)
                    ->setAutoincrement(true)
                    ->create(),
                Column::editor()
                    ->setUnquotedName('title')
                    ->setTypeName(Types::STRING)
                    ->setNotNull(false)
                    ->create(),
            )
            ->setPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('id')->create())
            ->create();
    }

    /** The sequence ORM declares for an entity with the SEQUENCE strategy on table "ticket". */
    private static function ormSequence(): Sequence
    {
        return Sequence::editor()->setUnquotedName('ticket_id_seq')->setAllocationSize(1)->setInitialValue(1)->create();
    }

    /**
     * @param list<Sequence> $sequences
     *
     * @return list<string>
     */
    private static function sequenceNames(array $sequences): array
    {
        $names = array_map(
            static fn (Sequence $sequence): string => $sequence->getObjectName()->getUnqualifiedName()->getValue(),
            $sequences,
        );

        sort($names);

        return $names;
    }
}
