<?php

declare(strict_types=1);

namespace MartinJuul\DBAL\DuckDB\Tests\Functional\Schema;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\DefaultExpression\CurrentDate;
use Doctrine\DBAL\Schema\DefaultExpression\CurrentTime;
use Doctrine\DBAL\Schema\DefaultExpression\CurrentTimestamp;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\Index\IndexedColumn;
use Doctrine\DBAL\Schema\Index\IndexType;
use Doctrine\DBAL\Schema\Name\UnqualifiedName;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\SchemaDiff;
use Doctrine\DBAL\Schema\Sequence;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\Metadata\Storage\TableMetadataStorage;
use Doctrine\Migrations\Version\AlphabeticalComparator;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use MartinJuul\DBAL\DuckDB\Platform\DuckDBPlatform;
use MartinJuul\DBAL\DuckDB\Schema\DuckDBSchemaManager;
use MartinJuul\DBAL\DuckDB\Tests\Functional\FunctionalTestCase;
use MartinJuul\DBAL\DuckDB\Types\NativeType;

use function array_map;
use function array_merge;
use function array_values;
use function implode;
use function sort;
use function sprintf;

final class IntrospectionTest extends FunctionalTestCase
{
    private Connection $connection;

    private DuckDBPlatform $platform;

    private DuckDBSchemaManager $schemaManager;

    protected function setUp(): void
    {
        [$this->connection, $this->platform, $this->schemaManager] = self::open();
    }

    public function testSchemaRoundTripsThroughTheLegacyIntrospection(): void
    {
        $declared = self::declaredSchema();

        $this->create($declared);

        $introspected = $this->schemaManager->introspectSchema();

        // Like PostgreSQL's "public", DuckDB's default schema is listed as a namespace; Doctrine Migrations adds it
        // to the schema it compares with, and DBAL never generates SQL for it.
        self::assertSame(['main'], $introspected->getNamespaces());

        self::assertSame([], $this->describe($this->compare($declared, $introspected), ignoreDefaultSchema: true));
        self::assertSame([], $this->describe($this->compare($introspected, $declared), ignoreDefaultSchema: true));
    }

    public function testSchemaRoundTripsThroughTheMetadataProvider(): void
    {
        $declared = self::declaredSchema();

        $this->create($declared);

        // The metadata provider qualifies sequences with their schema, as DBAL's PostgreSQL provider does.
        $introspected = Schema::editor()
            ->setDefaultNamespace('main')
            ->setTables(...$this->schemaManager->introspectTables())
            ->setSequences(...$this->schemaManager->introspectSequences())
            ->create();

        $diff = $this->compare($declared, $introspected);

        self::assertSame([], $this->describe($diff));
        self::assertTrue($diff->isEmpty());
        self::assertTrue($this->compare($introspected, $declared)->isEmpty());
    }

    public function testSingleTableIntrospectionMatchesTheDeclaration(): void
    {
        $declared = self::declaredSchema();

        $this->create($declared);

        $comparator = $this->schemaManager->createComparator();

        foreach ($declared->getTables() as $table) {
            $name = $table->getObjectName()->getUnqualifiedName()->getValue();

            self::assertTrue(
                $comparator->compareTables($table, $this->schemaManager->introspectTable($name))->isEmpty(),
                sprintf('introspectTable("%s") differs from the declaration.', $name),
            );

            self::assertTrue(
                $comparator->compareTables($table, $this->schemaManager->introspectTableByUnquotedName($name))
                    ->isEmpty(),
                sprintf('introspectTableByUnquotedName("%s") differs from the declaration.', $name),
            );
        }
    }

    public function testKeysKeepTheirDbalNames(): void
    {
        $declared = self::declaredSchema();

        $this->create($declared);

        $parent       = $declared->getTable('parent');
        $generatedKey = $this->generatedUniqueIndexName($parent);
        $legacy       = $this->schemaManager->introspectTable('parent');
        $current      = $this->schemaManager->introspectTableByUnquotedName('parent');

        self::assertSame('primary', $legacy->getPrimaryKey()?->getName());
        self::assertSame(['id'], self::columnNames($current->getPrimaryKeyConstraint()?->getColumnNames() ?? []));

        foreach ([$legacy, $current] as $table) {
            self::assertTrue($table->hasIndex('uniq_parent_label'), 'A named unique index keeps its name.');
            self::assertTrue($table->getIndex('uniq_parent_label')->isUnique());

            self::assertTrue($table->hasIndex($generatedKey), 'The inline UNIQUE constraint is a generated-name index.');
            self::assertTrue($table->getIndex($generatedKey)->isUnique());
            self::assertSame(['code'], self::columnNames(array_map(
                static fn (IndexedColumn $column): UnqualifiedName => $column->getColumnName(),
                $table->getIndex($generatedKey)->getIndexedColumns(),
            )));
            self::assertSame([], $table->getUniqueConstraints());
        }

        $membership  = $this->schemaManager->introspectTableByUnquotedName('membership');
        $foreignKeys = array_map(
            static fn (ForeignKeyConstraint $key): string => sprintf(
                '%s -> %s(%s)',
                implode(', ', $key->getUnquotedLocalColumns()),
                $key->getForeignTableName(),
                implode(', ', $key->getUnquotedForeignColumns()),
            ),
            $membership->getForeignKeys(),
        );

        sort($foreignKeys);

        self::assertSame(['parent_code -> parent(code)', 'parent_id -> parent(id)'], $foreignKeys);
    }

    public function testQuotedAndCurrentTimestampDefaultsAreNormalized(): void
    {
        $this->connection->executeStatement(<<<'SQL'
            CREATE TABLE defaults (
                quoted VARCHAR DEFAULT 'it''s',
                declared_now TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                function_now TIMESTAMPTZ DEFAULT now(),
                today DATE DEFAULT CURRENT_DATE,
                clock TIME DEFAULT CURRENT_TIME,
                nothing VARCHAR DEFAULT NULL,
                flag BOOLEAN DEFAULT true,
                amount DOUBLE DEFAULT -1.5,
                counter INTEGER DEFAULT 7
            )
            SQL);

        $legacy  = $this->schemaManager->introspectTable('defaults');
        $current = $this->schemaManager->introspectTableByUnquotedName('defaults');

        foreach ([$legacy, $current] as $table) {
            self::assertSame("it's", $table->getColumn('quoted')->getDefault());
            self::assertInstanceOf(CurrentTimestamp::class, $table->getColumn('declared_now')->getDefault());
            self::assertInstanceOf(CurrentTimestamp::class, $table->getColumn('function_now')->getDefault());
            self::assertInstanceOf(CurrentDate::class, $table->getColumn('today')->getDefault());
            self::assertInstanceOf(CurrentTime::class, $table->getColumn('clock')->getDefault());
            self::assertNull($table->getColumn('nothing')->getDefault());
            self::assertTrue($table->getColumn('flag')->getDefault());
            self::assertSame(-1.5, $table->getColumn('amount')->getDefault());
            self::assertSame(7, $table->getColumn('counter')->getDefault());
        }
    }

    public function testNextvalDefaultsAreKeptAsWritten(): void
    {
        $this->connection->executeStatement('CREATE SEQUENCE ticket_seq');
        $this->connection->executeStatement("CREATE TABLE ticket (id INTEGER DEFAULT nextval('ticket_seq'))");

        $column = $this->schemaManager->introspectTableByUnquotedName('ticket')->getColumn('id');

        self::assertSame("nextval('ticket_seq')", $column->getDefault());
    }

    public function testUnmappedTypesIntrospectAsNativeAndReDiffWithoutChanges(): void
    {
        $nativeColumns = [
            'e' => "ENUM('a', 'b')",
            'b' => 'BIT',
            'u' => 'UNION(n INTEGER, s VARCHAR)',
            't' => 'TINYINT',
            'ut' => 'UTINYINT',
            'ns' => 'TIMESTAMP_NS',
            'tz' => 'TIME WITH TIME ZONE',
            'big' => 'BIGNUM',
        ];

        // Nested and wide types get their own DBAL types in a later unit; until then they must round-trip as well.
        $nestedColumns = [
            'l' => 'INTEGER[]',
            'a' => 'INTEGER[3]',
            's' => 'STRUCT(x INTEGER, "y z" VARCHAR[])',
            'm' => 'MAP(VARCHAR, INTEGER)',
            'h' => 'HUGEINT',
            'i' => 'INTERVAL',
        ];

        $definitions = [];

        foreach (array_merge($nativeColumns, $nestedColumns) as $name => $type) {
            $definitions[] = $name . ' ' . $type;
        }

        $this->connection->executeStatement('CREATE TABLE odd (' . implode(', ', $definitions) . ')');

        $legacy  = $this->schemaManager->introspectTable('odd');
        $current = $this->schemaManager->introspectTableByUnquotedName('odd');

        foreach ([$legacy, $current] as $table) {
            foreach ($nativeColumns as $name => $type) {
                $column = $table->getColumn($name);

                self::assertSame(NativeType::NAME, $column->getTypeName(), $name);
                self::assertSame(
                    $type,
                    Type::getType(NativeType::NAME)->getSQLDeclaration($column->toArray(true), $this->platform),
                    $name,
                );
            }
        }

        $comparator = $this->schemaManager->createComparator();

        self::assertTrue($comparator->compareTables($legacy, $current)->isEmpty());
        self::assertTrue(
            $comparator->compareTables($current, $this->schemaManager->introspectTableByUnquotedName('odd'))->isEmpty(),
        );

        [$copy, , $copySchemaManager] = self::open();

        foreach ($this->platform->getCreateTableSQL($current) as $sql) {
            $copy->executeStatement($sql);
        }

        self::assertTrue(
            $comparator->compareTables($current, $copySchemaManager->introspectTableByUnquotedName('odd'))->isEmpty(),
        );
    }

    public function testTemporaryAndAttachedObjectsAreNotListed(): void
    {
        $other = $this->temporaryDatabasePath();

        $this->connection->executeStatement('CREATE TABLE kept (id INTEGER)');
        $this->connection->executeStatement('CREATE SEQUENCE kept_seq');
        $this->connection->executeStatement('CREATE TEMP TABLE scratch (id INTEGER)');
        $this->connection->executeStatement('CREATE TEMP SEQUENCE scratch_seq');
        $this->connection->executeStatement(sprintf("ATTACH '%s' AS other", $other));
        $this->connection->executeStatement('CREATE TABLE other.elsewhere (id INTEGER)');
        $this->connection->executeStatement('CREATE SEQUENCE other.elsewhere_seq');

        self::assertSame(['kept'], $this->schemaManager->listTableNames());
        self::assertSame(['kept'], self::tableNames($this->schemaManager->listTables()));
        self::assertSame(['kept'], self::tableNames($this->schemaManager->introspectTables()));
        self::assertSame(['kept'], self::tableNames($this->schemaManager->introspectSchema()->getTables()));
        self::assertSame(
            ['kept'],
            array_map(
                static fn ($name): string => $name->getUnqualifiedName()->getValue(),
                $this->schemaManager->introspectTableNames(),
            ),
        );

        self::assertSame(['kept_seq'], self::sequenceNames($this->schemaManager->listSequences()));
        self::assertSame(['kept_seq'], self::sequenceNames($this->schemaManager->introspectSequences()));
    }

    public function testTablesOutsideTheDefaultSchemaAreQualified(): void
    {
        $this->connection->executeStatement('CREATE SCHEMA reporting');
        $this->connection->executeStatement('CREATE TABLE reporting.summary (id INTEGER PRIMARY KEY)');
        $this->connection->executeStatement('CREATE TABLE reporting.line (summary_id INTEGER REFERENCES reporting.summary (id))');

        self::assertSame(['main', 'reporting'], $this->schemaManager->listSchemaNames());
        self::assertSame(['reporting.line', 'reporting.summary'], $this->schemaManager->listTableNames());

        $line = $this->schemaManager->introspectTableByUnquotedName('line', 'reporting');

        $foreignKeys = array_values($line->getForeignKeys());

        self::assertCount(1, $foreignKeys);
        self::assertSame('reporting.summary', $foreignKeys[0]->getForeignTableName());
    }

    public function testOrmAssociationToAUniqueColumnReDiffsWithoutChanges(): void
    {
        $configuration = ORMSetup::createAttributeMetadataConfig([], true);
        $configuration->enableNativeLazyObjects(true);

        $entityManager = new EntityManager($this->connection, $configuration);

        // The schema tool reads only the mapping, so classes without properties stand in for the entities.
        $customerClass = (new class () {
        })::class;
        $purchaseClass = (new class () {
        })::class;

        $customer = new ClassMetadata($customerClass);
        $customer->setPrimaryTable(['name' => 'customer']);
        $customer->mapField(['fieldName' => 'id', 'type' => Types::INTEGER, 'id' => true]);
        $customer->mapField(['fieldName' => 'code', 'type' => Types::STRING, 'length' => 20, 'unique' => true]);
        $customer->mapField(['fieldName' => 'since', 'type' => Types::DATETIME_IMMUTABLE]);

        $purchase = new ClassMetadata($purchaseClass);
        $purchase->setPrimaryTable(['name' => 'purchase']);
        $purchase->mapField(['fieldName' => 'id', 'type' => Types::INTEGER, 'id' => true]);
        $purchase->mapField(['fieldName' => 'note', 'type' => Types::TEXT, 'nullable' => true, 'options' => ['comment' => 'Free text']]);
        $purchase->mapManyToOne([
            'fieldName' => 'customer',
            'targetEntity' => $customerClass,
            'joinColumns' => [['name' => 'customer_code', 'referencedColumnName' => 'code']],
        ]);

        $entityManager->getMetadataFactory()->setMetadataFor($customerClass, $customer);
        $entityManager->getMetadataFactory()->setMetadataFor($purchaseClass, $purchase);

        $schemaTool = new SchemaTool($entityManager);
        $schemaTool->createSchema([$customer, $purchase]);

        self::assertSame([], $schemaTool->getUpdateSchemaSql([$customer, $purchase]));
    }

    public function testMigrationsMetadataTableNeedsNoUpdate(): void
    {
        (new TableMetadataStorage($this->connection, new AlphabeticalComparator()))->ensureInitialized();

        // A second storage compares the introspected table with the one it expects; any difference would alter it,
        // which this platform cannot do yet.
        (new TableMetadataStorage($this->connection, new AlphabeticalComparator()))->ensureInitialized();

        self::assertSame(['doctrine_migration_versions'], $this->schemaManager->listTableNames());
    }

    /** @return array{Connection, DuckDBPlatform, DuckDBSchemaManager} */
    private static function open(): array
    {
        $connection = self::connect();
        $platform   = $connection->getDatabasePlatform();
        self::assertInstanceOf(DuckDBPlatform::class, $platform);

        $schemaManager = $connection->createSchemaManager();
        self::assertInstanceOf(DuckDBSchemaManager::class, $schemaManager);

        return [$connection, $platform, $schemaManager];
    }

    /**
     * Every DBAL built-in type, nullable and not-null columns, defaults, comments, a composite primary key, unique
     * and plain indexes, a foreign key to a primary key, one to a non-primary unique column, and a sequence.
     */
    private static function declaredSchema(): Schema
    {
        $parent = Table::editor()
            ->setUnquotedName('parent')
            ->setColumns(
                Column::editor()->setUnquotedName('id')->setTypeName(Types::INTEGER)->create(),
                Column::editor()->setUnquotedName('code')->setTypeName(Types::STRING)->setLength(20)->create(),
                Column::editor()
                    ->setUnquotedName('label')
                    ->setTypeName(Types::STRING)
                    ->setLength(100)
                    ->setNotNull(false)
                    ->setDefaultValue("it's")
                    ->setComment('Shown to people')
                    ->create(),
            )
            ->setPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('id')->create())
            ->addIndex(Index::editor()->setType(IndexType::UNIQUE)->setUnquotedColumnNames('code'))
            ->addIndex(
                Index::editor()
                    ->setUnquotedName('uniq_parent_label')
                    ->setType(IndexType::UNIQUE)
                    ->setUnquotedColumnNames('label')
                    ->create(),
            )
            ->setComment('Parents')
            ->create();

        $membership = Table::editor()
            ->setUnquotedName('membership')
            ->setColumns(
                Column::editor()->setUnquotedName('parent_id')->setTypeName(Types::INTEGER)->create(),
                Column::editor()->setUnquotedName('member_no')->setTypeName(Types::SMALLINT)->create(),
                Column::editor()->setUnquotedName('parent_code')->setTypeName(Types::STRING)->setNotNull(false)->create(),
                Column::editor()->setUnquotedName('note')->setTypeName(Types::TEXT)->setNotNull(false)->create(),
            )
            ->setPrimaryKeyConstraint(
                PrimaryKeyConstraint::editor()->setUnquotedColumnNames('parent_id', 'member_no')->create(),
            )
            ->addIndex(Index::editor()->setUnquotedName('idx_membership_note')->setUnquotedColumnNames('note')->create())
            ->addForeignKeyConstraint(
                ForeignKeyConstraint::editor()
                    ->setUnquotedReferencingColumnNames('parent_id')
                    ->setUnquotedReferencedTableName('parent')
                    ->setUnquotedReferencedColumnNames('id')
                    ->create(),
            )
            ->addForeignKeyConstraint(
                ForeignKeyConstraint::editor()
                    ->setUnquotedReferencingColumnNames('parent_code')
                    ->setUnquotedReferencedTableName('parent')
                    ->setUnquotedReferencedColumnNames('code')
                    ->create(),
            )
            ->create();

        $everyType = Table::editor()
            ->setUnquotedName('every_type')
            ->setColumns(
                Column::editor()->setUnquotedName('id')->setTypeName(Types::BIGINT)->create(),
                self::column('c_ascii_string', Types::ASCII_STRING, length: 30),
                self::column('c_bigint', Types::BIGINT, default: '9007199254740993'),
                self::column('c_binary', Types::BINARY, length: 16),
                self::column('c_blob', Types::BLOB),
                self::column('c_boolean', Types::BOOLEAN, notNull: true, default: true),
                self::column('c_boolean_false', Types::BOOLEAN, default: false),
                self::column('c_date', Types::DATE_MUTABLE, default: new CurrentDate()),
                self::column('c_date_immutable', Types::DATE_IMMUTABLE, default: '2024-02-29'),
                self::column('c_dateinterval', Types::DATEINTERVAL),
                self::column('c_datetime', Types::DATETIME_MUTABLE, notNull: true, default: new CurrentTimestamp()),
                self::column('c_datetime_immutable', Types::DATETIME_IMMUTABLE, default: '2024-02-29 10:00:00'),
                self::column('c_datetime_utc', Types::DATETIME_UTC_MUTABLE),
                self::column('c_datetime_utc_immutable', Types::DATETIME_UTC_IMMUTABLE),
                self::column('c_datetimetz', Types::DATETIMETZ_MUTABLE, default: new CurrentTimestamp()),
                self::column('c_datetimetz_immutable', Types::DATETIMETZ_IMMUTABLE),
                self::column('c_decimal', Types::DECIMAL, precision: 10, scale: 2, default: '9.99'),
                self::column('c_decimal_float', Types::DECIMAL, precision: 18, scale: 4, default: 1.5),
                self::column('c_number', Types::NUMBER, precision: 12, scale: 3),
                Column::editor()
                    ->setUnquotedName('c_enum')
                    ->setTypeName(Types::ENUM)
                    ->setValues(['draft', 'published'])
                    ->setNotNull(false)
                    ->create(),
                self::column('c_float', Types::FLOAT, default: -0.25),
                self::column('c_guid', Types::GUID, default: '00000000-0000-0000-0000-000000000000'),
                self::column('c_integer', Types::INTEGER, notNull: true, default: 42, comment: 'The answer'),
                self::column('c_json', Types::JSON, default: '{"a":1}'),
                self::column('c_json_object', Types::JSON_OBJECT),
                self::column('c_jsonb', Types::JSONB),
                self::column('c_jsonb_object', Types::JSONB_OBJECT),
                self::column('c_simple_array', Types::SIMPLE_ARRAY),
                self::column('c_smallfloat', Types::SMALLFLOAT, default: 2.5),
                self::column('c_smallint', Types::SMALLINT, default: -3),
                self::column('c_string', Types::STRING, length: 255, notNull: true, default: ''),
                self::column('c_text', Types::TEXT, comment: "Can't be empty"),
                self::column('c_time', Types::TIME_MUTABLE, default: new CurrentTime()),
                self::column('c_time_immutable', Types::TIME_IMMUTABLE, default: '12:30:00'),
            )
            ->setPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('id')->create())
            ->addIndex(
                Index::editor()
                    ->setUnquotedName('idx_every_type_pair')
                    ->setUnquotedColumnNames('c_integer', 'c_string')
                    ->create(),
            )
            ->create();

        return Schema::editor()
            ->setTables($parent, $membership, $everyType)
            ->setSequences(
                Sequence::editor()->setUnquotedName('invoice_seq')->setAllocationSize(5)->setInitialValue(10)->create(),
            )
            ->create();
    }

    /** @param non-empty-string $name */
    private static function column(
        string $name,
        string $type,
        int|null $length = null,
        int|null $precision = null,
        int $scale = 0,
        bool $notNull = false,
        mixed $default = null,
        string $comment = '',
    ): Column {
        $editor = Column::editor()
            ->setUnquotedName($name)
            ->setTypeName($type)
            ->setLength($length)
            ->setPrecision($precision)
            ->setScale($scale)
            ->setNotNull($notNull)
            ->setDefaultValue($default);

        if ($comment !== '') {
            $editor->setComment($comment);
        }

        return $editor->create();
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
     * Lists the differences in a schema diff in words, so a failure shows what differs.
     *
     * @return list<string>
     */
    private function describe(SchemaDiff $diff, bool $ignoreDefaultSchema = false): array
    {
        $differences = [];

        foreach ($diff->getCreatedSchemas() as $schema) {
            if ($ignoreDefaultSchema && $schema === 'main') {
                continue;
            }

            $differences[] = 'created schema ' . $schema;
        }

        foreach ($diff->getDroppedSchemas() as $schema) {
            if ($ignoreDefaultSchema && $schema === 'main') {
                continue;
            }

            $differences[] = 'dropped schema ' . $schema;
        }

        foreach ($diff->getCreatedTables() as $table) {
            $differences[] = 'created table ' . $table->getObjectName()->toString();
        }

        foreach ($diff->getDroppedTables() as $table) {
            $differences[] = 'dropped table ' . $table->getObjectName()->toString();
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

        foreach ($diff->getAlteredTables() as $tableDiff) {
            $table = $tableDiff->getOldTable()->getObjectName()->toString();

            foreach ($tableDiff->getAddedColumns() as $column) {
                $differences[] = sprintf('%s: added column %s', $table, $column->getObjectName()->toString());
            }

            foreach ($tableDiff->getDroppedColumns() as $column) {
                $differences[] = sprintf('%s: dropped column %s', $table, $column->getObjectName()->toString());
            }

            foreach ($tableDiff->getChangedColumns() as $columnDiff) {
                $differences[] = sprintf(
                    '%s: changed column %s from [%s] to [%s]',
                    $table,
                    $columnDiff->getOldColumn()->getObjectName()->toString(),
                    $this->platform->getColumnDeclarationSQL('', $columnDiff->getOldColumn()->toArray(true)) . ' '
                        . $columnDiff->getOldColumn()->getComment(),
                    $this->platform->getColumnDeclarationSQL('', $columnDiff->getNewColumn()->toArray(true)) . ' '
                        . $columnDiff->getNewColumn()->getComment(),
                );
            }

            foreach ($tableDiff->getAddedIndexes() as $index) {
                $differences[] = sprintf('%s: added index %s', $table, $index->getObjectName()->toString());
            }

            foreach ($tableDiff->getDroppedIndexes() as $index) {
                $differences[] = sprintf('%s: dropped index %s', $table, $index->getObjectName()->toString());
            }

            foreach ($tableDiff->getRenamedIndexes() as $oldName => $index) {
                $differences[] = sprintf('%s: renamed index %s to %s', $table, $oldName, $index->getObjectName()->toString());
            }

            foreach ($tableDiff->getAddedForeignKeys() as $foreignKey) {
                $differences[] = sprintf('%s: added foreign key %s', $table, $foreignKey->getName());
            }

            foreach ($tableDiff->getDroppedForeignKeys() as $foreignKey) {
                $differences[] = sprintf('%s: dropped foreign key %s', $table, $foreignKey->getName());
            }
        }

        return $differences;
    }

    /** The name DBAL generates for the table's unnamed unique index on "code". */
    private function generatedUniqueIndexName(Table $table): string
    {
        foreach ($table->getIndexes() as $index) {
            if ($index->isUnique() && $index->getColumns() === ['code']) {
                return $index->getName();
            }
        }

        self::fail('The declared table has no unique index on "code".');
    }

    /**
     * @param list<UnqualifiedName> $names
     *
     * @return list<string>
     */
    private static function columnNames(array $names): array
    {
        return array_map(static fn (UnqualifiedName $name): string => $name->getIdentifier()->getValue(), $names);
    }

    /**
     * @param array<Table> $tables
     *
     * @return list<string>
     */
    private static function tableNames(array $tables): array
    {
        $names = array_map(
            static fn (Table $table): string => $table->getObjectName()->getUnqualifiedName()->getValue(),
            array_values($tables),
        );

        sort($names);

        return $names;
    }

    /**
     * @param list<Sequence> $sequences
     *
     * @return list<string>
     */
    private static function sequenceNames(array $sequences): array
    {
        return array_map(
            static fn (Sequence $sequence): string => $sequence->getObjectName()->getUnqualifiedName()->getValue(),
            $sequences,
        );
    }
}
