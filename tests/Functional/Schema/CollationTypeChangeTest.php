<?php

declare(strict_types=1);

namespace MartinJuul\DBAL\DuckDB\Tests\Functional\Schema;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\ColumnEditor;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\SchemaDiff;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use MartinJuul\DBAL\DuckDB\Driver;
use MartinJuul\DBAL\DuckDB\Driver\Middleware\LogSchemaNotices;
use MartinJuul\DBAL\DuckDB\Schema\DuckDBSchemaManager;
use MartinJuul\DBAL\DuckDB\Tests\Functional\Fixtures\RecordingLogger;
use MartinJuul\DBAL\DuckDB\Tests\Functional\FunctionalTestCase;
use Psr\Log\LogLevel;

use function array_column;
use function implode;
use function preg_match_all;

use const PREG_SET_ORDER;

/**
 * Giving a collated column a type without collation drops the collation, as
 * DuckDB's ALTER COLUMN TYPE does, whether the change runs natively or as a
 * table rebuild, and logs a notice naming the column and the collation.
 */
final class CollationTypeChangeTest extends FunctionalTestCase
{
    private const string NOTICE = 'DuckDB drops the collation NOCASE of column t.code: its new type INTEGER has no '
        . 'collation; only VARCHAR columns have one.';

    private RecordingLogger $logger;

    private Connection $connection;

    protected function setUp(): void
    {
        $this->logger     = new RecordingLogger();
        $this->connection = DriverManager::getConnection(
            ['driverClass' => Driver::class, 'memory' => true],
            (new Configuration())->setMiddlewares([new LogSchemaNotices($this->logger)]),
        );

        $this->connection->executeStatement(
            'CREATE TABLE t (id INTEGER PRIMARY KEY, code VARCHAR COLLATE NOCASE, label VARCHAR COLLATE NOCASE)',
        );
        $this->connection->executeStatement("INSERT INTO t VALUES (1, '7', 'Alpha')");
    }

    public function testANativeTypeChangeDropsTheCollationWithANotice(): void
    {
        $diff = $this->diff();

        self::assertSame(
            ['ALTER TABLE "t" ALTER COLUMN "code" TYPE INTEGER'],
            $this->connection->getDatabasePlatform()->getAlterSchemaSQL($diff),
        );

        $this->schemaManager()->alterSchema($diff);

        $this->assertChanged();
        self::assertSame([self::NOTICE], $this->notices());
    }

    public function testARebuildDropsTheCollationWithANoticeAndKeepsTheOthers(): void
    {
        $this->connection->executeStatement('CREATE INDEX t_label ON t (label)');

        $diff = $this->diff();

        self::assertStringContainsString(
            'CREATE TABLE t (id INTEGER NOT NULL, code INTEGER DEFAULT NULL, label VARCHAR COLLATE NOCASE',
            implode("\n", $this->connection->getDatabasePlatform()->getAlterSchemaSQL($diff)),
        );
        self::assertSame([self::NOTICE], $this->notices());

        $this->logger->records = [];

        $this->schemaManager()->alterSchema($diff);

        $this->assertChanged();
        self::assertSame([self::NOTICE], $this->notices());
        self::assertSame(1, $this->connection->fetchOne(
            "SELECT count(*) FROM duckdb_indexes() WHERE index_name = 't_label'",
        ));
    }

    public function testAlterTableLogsTheNoticeForANativeTypeChange(): void
    {
        $schemaManager = $this->schemaManager();
        $old           = $schemaManager->introspectTableByUnquotedName('t');

        $schemaManager->alterTable($schemaManager->createComparator()->compareTables($old, self::withIntegerCode($old)));

        $this->assertChanged();
        self::assertSame([self::NOTICE], $this->notices());
    }

    public function testAlterTableLogsTheNoticeForARebuild(): void
    {
        $this->connection->executeStatement('CREATE INDEX t_label ON t (label)');

        $schemaManager = $this->schemaManager();
        $old           = $schemaManager->introspectTableByUnquotedName('t');

        $schemaManager->alterTable($schemaManager->createComparator()->compareTables($old, self::withIntegerCode($old)));

        $this->assertChanged();
        self::assertSame([self::NOTICE], $this->notices());
    }

    public function testAChangeThatKeepsTheTypeLogsNothing(): void
    {
        $this->connection->executeStatement('CREATE INDEX t_label ON t (label)');

        $current = $this->schemaManager()->introspectSchema();
        $table   = $current->getTable('t')->edit()
            ->renameColumnByUnquotedName('label', 'title')
            ->create();

        $this->schemaManager()->alterSchema(
            $this->schemaManager()->createComparator()->compareSchemas($current, new Schema([$table])),
        );

        self::assertSame(
            ['id' => 'INTEGER', 'code' => 'VARCHAR COLLATE NOCASE', 'title' => 'VARCHAR COLLATE NOCASE'],
            self::declarations($this->connection),
        );
        self::assertSame([], $this->logger->records);
    }

    public function testWithoutALoggerTheChangeSucceeds(): void
    {
        $connection = self::connect();
        $connection->executeStatement(
            'CREATE TABLE t (id INTEGER PRIMARY KEY, code VARCHAR COLLATE NOCASE, label VARCHAR COLLATE NOCASE)',
        );
        $connection->executeStatement('CREATE INDEX t_label ON t (label)');
        $connection->executeStatement("INSERT INTO t VALUES (1, '7', 'Alpha')");

        $schemaManager = $connection->createSchemaManager();
        $current       = $schemaManager->introspectSchema();

        $schemaManager->alterSchema($schemaManager->createComparator()->compareSchemas(
            $current,
            new Schema([self::withIntegerCode($current->getTable('t'))]),
        ));

        self::assertSame(
            ['id' => 'INTEGER', 'code' => 'INTEGER', 'label' => 'VARCHAR COLLATE NOCASE'],
            self::declarations($connection),
        );
    }

    private function assertChanged(): void
    {
        self::assertSame(
            ['id' => 'INTEGER', 'code' => 'INTEGER', 'label' => 'VARCHAR COLLATE NOCASE'],
            self::declarations($this->connection),
        );
        self::assertSame(7, $this->connection->fetchOne('SELECT code FROM t'));
        self::assertSame(1, $this->connection->fetchOne("SELECT count(*) FROM t WHERE label = 'ALPHA'"));
    }

    /**
     * The type and collation of each column of table t, as DuckDB's catalog
     * reports them in the table's CREATE statement.
     *
     * @return array<string, string>
     */
    private static function declarations(Connection $connection): array
    {
        $sql = $connection->fetchOne("SELECT sql FROM duckdb_tables() WHERE table_name = 't'");
        self::assertIsString($sql);

        preg_match_all('/[(,] *"?([a-z]+)"? ([A-Z]+(?: COLLATE [A-Z]+)?)/', $sql, $matches, PREG_SET_ORDER);

        $declarations = [];

        foreach ($matches as $match) {
            $declarations[$match[1]] = $match[2];
        }

        return $declarations;
    }

    /** @return list<string> */
    private function notices(): array
    {
        foreach ($this->logger->records as $record) {
            self::assertSame(LogLevel::NOTICE, $record['level']);
        }

        return array_column($this->logger->records, 'message');
    }

    private function diff(): SchemaDiff
    {
        $current = $this->schemaManager()->introspectSchema();

        return $this->schemaManager()->createComparator()->compareSchemas(
            $current,
            new Schema([self::withIntegerCode($current->getTable('t'))]),
        );
    }

    private static function withIntegerCode(Table $table): Table
    {
        return $table->edit()
            ->modifyColumnByUnquotedName('code', static function (ColumnEditor $column): void {
                $column->setTypeName(Types::INTEGER);
            })
            ->create();
    }

    private function schemaManager(): DuckDBSchemaManager
    {
        $schemaManager = $this->connection->createSchemaManager();
        self::assertInstanceOf(DuckDBSchemaManager::class, $schemaManager);

        return $schemaManager;
    }
}
