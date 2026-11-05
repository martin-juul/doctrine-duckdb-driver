<?php

declare(strict_types=1);

namespace MartinJuul\DBAL\DuckDB\Tests\Unit\Platform;

use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\Platforms\Exception\NotSupported;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Doctrine\DBAL\Schema\ForeignKeyConstraint\ReferentialAction;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\Index\IndexType;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Sequence;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Schema\UniqueConstraint;
use Doctrine\DBAL\TransactionIsolationLevel;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use MartinJuul\DBAL\DuckDB\Exception\ForeignKeyActionNotSupported;
use MartinJuul\DBAL\DuckDB\Exception\ForeignKeyCycle;
use MartinJuul\DBAL\DuckDB\Platform\DuckDBPlatform;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function implode;

final class DuckDBPlatformTest extends TestCase
{
    private DuckDBPlatform $platform;

    protected function setUp(): void
    {
        $this->platform = new DuckDBPlatform();
    }

    /** @return iterable<string, array{string, array<string, mixed>, string}> */
    public static function typeDeclarations(): iterable
    {
        yield 'string with length' => [Types::STRING, ['length' => 255], 'VARCHAR'];
        yield 'fixed string' => [Types::STRING, ['length' => 36, 'fixed' => true], 'VARCHAR'];
        yield 'ascii string' => [Types::ASCII_STRING, ['length' => 20], 'VARCHAR'];
        yield 'text' => [Types::TEXT, [], 'VARCHAR'];
        yield 'binary with length' => [Types::BINARY, ['length' => 16], 'BLOB'];
        yield 'fixed binary' => [Types::BINARY, ['length' => 16, 'fixed' => true], 'BLOB'];
        yield 'blob' => [Types::BLOB, [], 'BLOB'];
        yield 'guid' => [Types::GUID, [], 'UUID'];
        yield 'json' => [Types::JSON, [], 'JSON'];
        yield 'boolean' => [Types::BOOLEAN, [], 'BOOLEAN'];
        yield 'smallint' => [Types::SMALLINT, [], 'SMALLINT'];
        yield 'integer' => [Types::INTEGER, [], 'INTEGER'];
        yield 'bigint' => [Types::BIGINT, [], 'BIGINT'];
        yield 'float' => [Types::FLOAT, [], 'DOUBLE'];
        yield 'smallfloat' => [Types::SMALLFLOAT, [], 'FLOAT'];
        yield 'decimal' => [Types::DECIMAL, ['precision' => 10, 'scale' => 2], 'NUMERIC(10, 2)'];
        yield 'date' => [Types::DATE_IMMUTABLE, [], 'DATE'];
        yield 'time' => [Types::TIME_IMMUTABLE, [], 'TIME'];
        yield 'datetime' => [Types::DATETIME_IMMUTABLE, [], 'TIMESTAMP_S'];
        yield 'datetimetz' => [Types::DATETIMETZ_IMMUTABLE, [], 'TIMESTAMPTZ'];
    }

    /** @param array<string, mixed> $column */
    #[DataProvider('typeDeclarations')]
    public function testTypeDeclaration(string $type, array $column, string $expected): void
    {
        self::assertSame($expected, Type::getType($type)->getSQLDeclaration($column + ['name' => 'c'], $this->platform));
    }

    public function testQuoting(): void
    {
        self::assertSame('"a""b"', $this->platform->quoteSingleIdentifier('a"b'));
        self::assertSame("'it''s'", $this->platform->quoteStringLiteral("it's"));
    }

    public function testLimitAndOffset(): void
    {
        self::assertSame('SELECT 1 LIMIT 10 OFFSET 5', $this->platform->modifyLimitQuery('SELECT 1', 10, 5));
        self::assertSame('SELECT 1 LIMIT 10', $this->platform->modifyLimitQuery('SELECT 1', 10));
        self::assertSame('SELECT 1 OFFSET 5', $this->platform->modifyLimitQuery('SELECT 1', null, 5));
    }

    public function testSequenceSQL(): void
    {
        $sequence = Sequence::editor()
            ->setUnquotedName('order_seq')
            ->setAllocationSize(5)
            ->setInitialValue(10)
            ->setCacheSize(20)
            ->create();

        self::assertSame(
            'CREATE SEQUENCE "order_seq" INCREMENT BY 5 MINVALUE 10 START WITH 10',
            $this->platform->getCreateSequenceSQL($sequence),
        );

        self::assertSame("SELECT nextval('order_seq')", $this->platform->getSequenceNextValSQL('order_seq'));
        self::assertSame('DROP SEQUENCE order_seq', $this->platform->getDropSequenceSQL('order_seq'));
    }

    public function testCapabilities(): void
    {
        self::assertTrue($this->platform->supportsSequences());
        self::assertFalse($this->platform->supportsIdentityColumns());
        self::assertTrue($this->platform->supportsSavepoints());
        self::assertTrue($this->platform->supportsReleaseSavepoints());
        self::assertTrue($this->platform->supportsSchemas());
        self::assertTrue($this->platform->supportsCommentOnStatement());
        self::assertFalse($this->platform->supportsPartialIndexes());
        self::assertFalse($this->platform->supportsInlineColumnComments());
    }

    public function testSavepointStatementsStayStandardSql(): void
    {
        self::assertSame('SAVEPOINT sp1', $this->platform->createSavePoint('sp1'));
        self::assertSame('RELEASE SAVEPOINT sp1', $this->platform->releaseSavePoint('sp1'));
        self::assertSame('ROLLBACK TO SAVEPOINT sp1', $this->platform->rollbackSavePoint('sp1'));
    }

    public function testDateExpressions(): void
    {
        self::assertSame('(d + TO_DAYS(CAST(3 AS INTEGER)))', $this->platform->getDateAddDaysExpression('d', '3'));
        self::assertSame(
            '(d - TO_MONTHS(CAST((2 * 3) AS INTEGER)))',
            $this->platform->getDateSubQuartersExpression('d', '2'),
        );

        self::assertSame(
            "DATE_DIFF('day', CAST(b AS DATE), CAST(a AS DATE))",
            $this->platform->getDateDiffExpression('a', 'b'),
        );
    }

    public function testOnlySnapshotIsolationIsAccepted(): void
    {
        self::assertSame(
            TransactionIsolationLevel::REPEATABLE_READ,
            $this->platform->getDefaultTransactionIsolationLevel(),
        );

        $this->platform->getSetTransactionIsolationSQL(TransactionIsolationLevel::REPEATABLE_READ);

        $this->expectException(NotSupported::class);
        $this->platform->getSetTransactionIsolationSQL(TransactionIsolationLevel::READ_COMMITTED);
    }

    public function testNonPessimisticLockModesLeaveTheFromClauseAlone(): void
    {
        self::assertSame('t a', $this->platform->appendLockHint('t a', LockMode::NONE));
        self::assertSame('t a', $this->platform->appendLockHint('t a', LockMode::OPTIMISTIC));
    }

    /** @return iterable<string, array{LockMode}> */
    public static function pessimisticLockModes(): iterable
    {
        yield 'read' => [LockMode::PESSIMISTIC_READ];
        yield 'write' => [LockMode::PESSIMISTIC_WRITE];
    }

    #[DataProvider('pessimisticLockModes')]
    public function testPessimisticLockHintsAreRejected(LockMode $lockMode): void
    {
        $this->expectException(NotSupported::class);
        $this->expectExceptionMessageMatches('/DuckDB does not support row locks.*optimistic locking/s');

        $this->platform->appendLockHint('t a', $lockMode);
    }

    public function testForeignKeysCannotBeAddedOrDroppedOutsideTableCreation(): void
    {
        $foreignKey = self::foreignKey('fk_child_parent', 'parent_id', 'parent', 'id');

        try {
            $this->platform->getCreateForeignKeySQL($foreignKey, 'child');
            self::fail('Adding a foreign key to an existing table must be rejected');
        } catch (NotSupported) {
        }

        $this->expectException(NotSupported::class);
        $this->platform->getDropForeignKeySQL('fk_child_parent', 'child');
    }

    /** @return iterable<string, array{string, ReferentialAction, string}> */
    public static function rejectedReferentialActions(): iterable
    {
        yield 'on delete cascade' => ['onDelete', ReferentialAction::CASCADE, 'ON DELETE CASCADE'];
        yield 'on delete set null' => ['onDelete', ReferentialAction::SET_NULL, 'ON DELETE SET NULL'];
        yield 'on delete set default' => ['onDelete', ReferentialAction::SET_DEFAULT, 'ON DELETE SET DEFAULT'];
        yield 'on update cascade' => ['onUpdate', ReferentialAction::CASCADE, 'ON UPDATE CASCADE'];
    }

    #[DataProvider('rejectedReferentialActions')]
    public function testReferentialActionsAreRejectedNamingTableConstraintAndAction(
        string $event,
        ReferentialAction $action,
        string $expectedAction,
    ): void {
        $editor = ForeignKeyConstraint::editor()
            ->setUnquotedName('fk_child_parent')
            ->setUnquotedReferencingColumnNames('parent_id')
            ->setUnquotedReferencedTableName('parent')
            ->setUnquotedReferencedColumnNames('id');

        if ($event === 'onDelete') {
            $editor->setOnDeleteAction($action);
        } else {
            $editor->setOnUpdateAction($action);
        }

        $child = self::table('child', ['id', 'parent_id'], ['id'], foreignKeys: [$editor->create()]);

        try {
            $this->platform->getCreateTablesSQL([self::table('parent', ['id'], ['id']), $child]);
            self::fail('A foreign key with ' . $expectedAction . ' must be rejected');
        } catch (ForeignKeyActionNotSupported $e) {
            self::assertStringContainsString('"child"', $e->getMessage());
            self::assertStringContainsString('"fk_child_parent"', $e->getMessage());
            self::assertStringContainsString($expectedAction, $e->getMessage());
        }
    }

    public function testNoActionAndRestrictAreAccepted(): void
    {
        $foreignKey = ForeignKeyConstraint::editor()
            ->setUnquotedName('fk_child_parent')
            ->setUnquotedReferencingColumnNames('parent_id')
            ->setUnquotedReferencedTableName('parent')
            ->setUnquotedReferencedColumnNames('id')
            ->setOnDeleteAction(ReferentialAction::RESTRICT)
            ->setOnUpdateAction(ReferentialAction::NO_ACTION)
            ->create();

        $sql = $this->platform->getCreateTablesSQL([
            self::table('parent', ['id'], ['id']),
            self::table('child', ['id', 'parent_id'], ['id'], foreignKeys: [$foreignKey]),
        ]);

        self::assertStringContainsString('FOREIGN KEY (parent_id) REFERENCES parent (id)', implode("\n", $sql));
    }

    public function testCycleIsRejectedNamingBothTables(): void
    {
        $users = self::table(
            'users',
            ['id', 'last_post_id'],
            ['id'],
            foreignKeys: [self::foreignKey('fk_users_last_post', 'last_post_id', 'posts', 'id')],
        );

        $posts = self::table(
            'posts',
            ['id', 'author_id'],
            ['id'],
            foreignKeys: [self::foreignKey('fk_posts_author', 'author_id', 'users', 'id')],
        );

        try {
            $this->platform->getCreateTablesSQL([$users, $posts]);
            self::fail('A foreign-key cycle must be rejected');
        } catch (ForeignKeyCycle $e) {
            self::assertStringContainsString('"users"', $e->getMessage());
            self::assertStringContainsString('"posts"', $e->getMessage());
        }
    }

    public function testTablesAreOrderedParentsFirst(): void
    {
        $child  = self::table(
            'child',
            ['id', 'parent_id'],
            ['id'],
            foreignKeys: [self::foreignKey('fk_child_parent', 'parent_id', 'parent', 'id')],
        );
        $parent = self::table('parent', ['id'], ['id']);

        $sql = $this->platform->getCreateTablesSQL([$child, $parent]);

        self::assertStringStartsWith('CREATE TABLE parent ', $sql[0]);
        self::assertStringStartsWith('CREATE TABLE child ', $sql[1]);
        self::assertStringContainsString(
            'CONSTRAINT fk_child_parent FOREIGN KEY (parent_id) REFERENCES parent (id)',
            $sql[1],
        );

        self::assertSame(
            ['DROP TABLE "child"', 'DROP TABLE "parent"'],
            $this->platform->getDropTablesSQL([$parent, $child]),
        );
    }

    public function testReferencedUniqueIndexBecomesAnInlineUniqueConstraint(): void
    {
        $parent = self::table(
            'parent',
            ['id', 'code'],
            ['id'],
            indexes: [
                Index::editor()
                    ->setUnquotedName('uniq_parent_code')
                    ->setType(IndexType::UNIQUE)
                    ->setUnquotedColumnNames('code')
                    ->create(),
            ],
        );

        $child = self::table(
            'child',
            ['id', 'parent_code'],
            ['id'],
            foreignKeys: [self::foreignKey('fk_child_parent', 'parent_code', 'parent', 'code')],
        );

        $sql = $this->platform->getCreateTablesSQL([$parent, $child]);

        self::assertStringContainsString('UNIQUE ("code")', $sql[0]);
        self::assertStringNotContainsString('uniq_parent_code', implode("\n", $sql));
    }

    public function testUnreferencedUniqueConstraintBecomesANamedUniqueIndex(): void
    {
        $table = self::table(
            'account',
            ['id', 'email'],
            ['id'],
            uniqueConstraints: [
                UniqueConstraint::editor()->setUnquotedName('uniq_account_email')->setUnquotedColumnNames('email')
                    ->create(),
            ],
        );

        self::assertSame(
            [
                'CREATE TABLE account (id INTEGER NOT NULL, email INTEGER NOT NULL, PRIMARY KEY (id))',
                'CREATE UNIQUE INDEX uniq_account_email ON account (email)',
            ],
            $this->platform->getCreateTableSQL($table),
        );
    }

    public function testPartialIndexIsRejected(): void
    {
        $table = self::table(
            'account',
            ['id', 'email'],
            ['id'],
            indexes: [
                Index::editor()
                    ->setUnquotedName('idx_account_email')
                    ->setUnquotedColumnNames('email')
                    ->setPredicate('id > 0')
                    ->create(),
            ],
        );

        $this->expectException(NotSupported::class);
        $this->platform->getCreateTableSQL($table);
    }

    public function testDropIndexInSchemaQualifiesTheIndexName(): void
    {
        self::assertSame('DROP INDEX reporting.idx_t', $this->platform->getDropIndexSQL('idx_t', 'reporting.t'));
        self::assertSame('DROP INDEX idx_t', $this->platform->getDropIndexSQL('idx_t', 't'));
    }

    public function testCopyWithoutForeignKeysOmitsThemAndSkipsTheirChecks(): void
    {
        $users = self::table(
            'users',
            ['id', 'last_post_id'],
            ['id'],
            foreignKeys: [self::foreignKey('fk_users_last_post', 'last_post_id', 'posts', 'id')],
        );

        $posts = self::table(
            'posts',
            ['id', 'author_id'],
            ['id'],
            foreignKeys: [
                ForeignKeyConstraint::editor()
                    ->setUnquotedName('fk_posts_author')
                    ->setUnquotedReferencingColumnNames('author_id')
                    ->setUnquotedReferencedTableName('users')
                    ->setUnquotedReferencedColumnNames('id')
                    ->setOnDeleteAction(ReferentialAction::CASCADE)
                    ->create(),
            ],
        );

        $platform = $this->platform->withoutForeignKeys();

        self::assertTrue($platform->omitsForeignKeys());
        self::assertFalse($this->platform->omitsForeignKeys());

        $sql = implode("\n", $platform->getCreateTablesSQL([$users, $posts]));

        self::assertStringContainsString('CREATE TABLE users', $sql);
        self::assertStringContainsString('CREATE TABLE posts', $sql);
        self::assertStringNotContainsString('FOREIGN KEY', $sql);

        self::assertSame(['DROP TABLE "users"', 'DROP TABLE "posts"'], $platform->getDropTablesSQL([$users, $posts]));
    }

    /**
     * @param non-empty-string                 $name
     * @param non-empty-list<non-empty-string> $columns
     * @param non-empty-list<non-empty-string> $primaryKey
     * @param list<Index>                      $indexes
     * @param list<UniqueConstraint>           $uniqueConstraints
     * @param list<ForeignKeyConstraint>       $foreignKeys
     */
    private static function table(
        string $name,
        array $columns,
        array $primaryKey,
        array $indexes = [],
        array $uniqueConstraints = [],
        array $foreignKeys = [],
    ): Table {
        $editor = Table::editor()->setUnquotedName($name);

        foreach ($columns as $column) {
            $editor->addColumn(Column::editor()->setUnquotedName($column)->setTypeName(Types::INTEGER)->create());
        }

        return $editor
            ->setPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames(...$primaryKey)->create())
            ->setIndexes(...$indexes)
            ->setUniqueConstraints(...$uniqueConstraints)
            ->setForeignKeyConstraints(...$foreignKeys)
            ->create();
    }

    /**
     * @param non-empty-string $name
     * @param non-empty-string $column
     * @param non-empty-string $referencedTable
     * @param non-empty-string $referencedColumn
     */
    private static function foreignKey(
        string $name,
        string $column,
        string $referencedTable,
        string $referencedColumn,
    ): ForeignKeyConstraint {
        return ForeignKeyConstraint::editor()
            ->setUnquotedName($name)
            ->setUnquotedReferencingColumnNames($column)
            ->setUnquotedReferencedTableName($referencedTable)
            ->setUnquotedReferencedColumnNames($referencedColumn)
            ->create();
    }
}
