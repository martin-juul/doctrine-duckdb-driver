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
use MartinJuul\DBAL\DuckDB\Platform\DuckDBPlatform;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_map;
use function array_reverse;
use function implode;
use function in_array;
use function preg_match_all;
use function sort;

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

    /** @return iterable<string, array{'onDelete'|'onUpdate', ReferentialAction}> */
    public static function unenforceableReferentialActions(): iterable
    {
        yield 'on delete cascade' => ['onDelete', ReferentialAction::CASCADE];
        yield 'on delete set null' => ['onDelete', ReferentialAction::SET_NULL];
        yield 'on delete set default' => ['onDelete', ReferentialAction::SET_DEFAULT];
        yield 'on update cascade' => ['onUpdate', ReferentialAction::CASCADE];
    }

    /** @param 'onDelete'|'onUpdate' $event */
    #[DataProvider('unenforceableReferentialActions')]
    public function testForeignKeyWithAnUnenforceableActionIsOmittedAndOthersAreKept(
        string $event,
        ReferentialAction $action,
    ): void {
        $child = self::table(
            'child',
            ['id', 'parent_id', 'other_id'],
            ['id'],
            foreignKeys: [
                self::foreignKeyWithAction('fk_child_parent', 'parent_id', 'parent', 'id', $event, $action),
                self::foreignKey('fk_child_other', 'other_id', 'other', 'id'),
            ],
        );

        $sql = $this->platform->getCreateTablesSQL([
            self::table('parent', ['id'], ['id']),
            self::table('other', ['id'], ['id']),
            $child,
        ]);

        self::assertSame(
            'CREATE TABLE child (id INTEGER NOT NULL, parent_id INTEGER NOT NULL, other_id INTEGER NOT NULL, '
                . 'PRIMARY KEY (id), CONSTRAINT fk_child_other FOREIGN KEY (other_id) REFERENCES other (id))',
            $sql[2],
        );
        self::assertStringNotContainsString('fk_child_parent', implode("\n", $sql));
    }

    public function testCycleThroughAnOmittedForeignKeyIsAccepted(): void
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
                self::foreignKeyWithAction(
                    'fk_posts_author',
                    'author_id',
                    'users',
                    'id',
                    'onDelete',
                    ReferentialAction::CASCADE,
                ),
            ],
        );

        $sql = $this->platform->getCreateTablesSQL([$users, $posts]);

        self::assertStringStartsWith('CREATE TABLE posts ', $sql[0]);
        self::assertStringStartsWith('CREATE TABLE users ', $sql[2]);
        self::assertStringNotContainsString('fk_posts_author', implode("\n", $sql));
        self::assertSame(['DROP TABLE "users"', 'DROP TABLE "posts"'], $this->platform->getDropTablesSQL([$posts, $users]));
    }

    public function testColumnsReferencedOnlyByAnOmittedForeignKeyGetNoInlineUniqueConstraint(): void
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
            foreignKeys: [
                self::foreignKeyWithAction(
                    'fk_child_parent',
                    'parent_code',
                    'parent',
                    'code',
                    'onDelete',
                    ReferentialAction::SET_NULL,
                ),
            ],
        );

        self::assertSame(
            [
                'CREATE TABLE parent (id INTEGER NOT NULL, code INTEGER NOT NULL, PRIMARY KEY (id))',
                'CREATE UNIQUE INDEX uniq_parent_code ON parent (code)',
                'CREATE TABLE child (id INTEGER NOT NULL, parent_code INTEGER NOT NULL, PRIMARY KEY (id))',
                'CREATE INDEX IDX_22B354294AF6062C ON child (parent_code)',
            ],
            $this->platform->getCreateTablesSQL([$parent, $child]),
        );
    }

    /** A composite key's column pairs follow the order of the referenced primary key, which DuckDB requires. */
    public function testCompositeForeignKeyFollowsTheReferencedPrimaryKeyOrder(): void
    {
        $member = self::table('member', ['uid', 'account_id'], ['uid', 'account_id']);

        $privilege = self::table(
            'privilege',
            ['id', 'member_account_id', 'member_uid'],
            ['id'],
            foreignKeys: [
                ForeignKeyConstraint::editor()
                    ->setUnquotedName('fk_privilege_member')
                    ->setUnquotedReferencingColumnNames('member_account_id', 'member_uid')
                    ->setUnquotedReferencedTableName('member')
                    ->setUnquotedReferencedColumnNames('account_id', 'uid')
                    ->create(),
            ],
        );

        $sql = $this->platform->getCreateTablesSQL([$privilege, $member]);

        self::assertStringContainsString(
            'CONSTRAINT fk_privilege_member FOREIGN KEY (member_uid, member_account_id) '
                . 'REFERENCES member (uid, account_id)',
            $sql[1],
        );
    }

    /** Every foreign key to the same non-primary column set follows the order of its inline UNIQUE constraint. */
    public function testCompositeForeignKeysFollowTheInlineUniqueConstraintOrder(): void
    {
        $parent = self::table(
            'parent',
            ['id', 'a', 'b'],
            ['id'],
            uniqueConstraints: [UniqueConstraint::editor()->setUnquotedColumnNames('a', 'b')->create()],
        );

        $child = self::table(
            'child',
            ['id', 'x', 'y'],
            ['id'],
            foreignKeys: [
                ForeignKeyConstraint::editor()
                    ->setUnquotedName('fk_child_ab')
                    ->setUnquotedReferencingColumnNames('x', 'y')
                    ->setUnquotedReferencedTableName('parent')
                    ->setUnquotedReferencedColumnNames('a', 'b')
                    ->create(),
                ForeignKeyConstraint::editor()
                    ->setUnquotedName('fk_child_ba')
                    ->setUnquotedReferencingColumnNames('x', 'y')
                    ->setUnquotedReferencedTableName('parent')
                    ->setUnquotedReferencedColumnNames('b', 'a')
                    ->create(),
            ],
        );

        $sql = $this->platform->getCreateTablesSQL([$parent, $child]);

        self::assertStringContainsString('UNIQUE ("a", "b")', $sql[0]);
        self::assertStringContainsString('CONSTRAINT fk_child_ab FOREIGN KEY (x, y) REFERENCES parent (a, b)', $sql[1]);
        self::assertStringContainsString('CONSTRAINT fk_child_ba FOREIGN KEY (y, x) REFERENCES parent (a, b)', $sql[1]);
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

    /** Covers AE12: the nullable key of a two-table cycle is left out, and the tables are created parents first. */
    public function testTwoTableCycleLeavesOutTheNullableKey(): void
    {
        $sql = $this->platform->getCreateTablesSQL([self::users(), self::posts()]);

        self::assertSame(
            [
                'CREATE TABLE users (id INTEGER NOT NULL, last_post_id INTEGER DEFAULT NULL, PRIMARY KEY (id))',
                'CREATE INDEX IDX_1483A5E92D053F64 ON users (last_post_id)',
                'CREATE TABLE posts (id INTEGER NOT NULL, author_id INTEGER NOT NULL, PRIMARY KEY (id), '
                    . 'CONSTRAINT fk_posts_author FOREIGN KEY (author_id) REFERENCES users (id))',
                'CREATE INDEX IDX_885DBAFAF675F31B ON posts (author_id)',
            ],
            $sql,
        );
        self::assertSame(
            ['DROP TABLE "posts"', 'DROP TABLE "users"'],
            $this->platform->getDropTablesSQL([self::users(), self::posts()]),
        );
    }

    public function testThreeTableCycleLeavesOutOneKey(): void
    {
        $sql = $this->platform->getCreateTablesSQL([
            self::table('a', ['id', 'b_id'], ['id'], foreignKeys: [self::foreignKey('fk_a_b', 'b_id', 'b', 'id')]),
            self::table('b', ['id', 'c_id'], ['id'], foreignKeys: [self::foreignKey('fk_b_c', 'c_id', 'c', 'id')]),
            self::table(
                'c',
                ['id', 'a_id'],
                ['id'],
                foreignKeys: [self::foreignKey('fk_c_a', 'a_id', 'a', 'id')],
                nullable: ['a_id'],
            ),
        ]);

        self::assertSame(['fk_a_b', 'fk_b_c'], self::createdForeignKeys($sql));
        self::assertSame(['c', 'b', 'a'], self::createdTables($sql));
    }

    /** Two cycles that share the key a -> b: leaving out that key alone breaks both. */
    public function testCyclesSharingAKeyLeaveOutOnlyThatKey(): void
    {
        $sql = $this->platform->getCreateTablesSQL([
            self::table(
                'a',
                ['id', 'b_id'],
                ['id'],
                foreignKeys: [self::foreignKey('fk_a_b', 'b_id', 'b', 'id')],
            ),
            self::table(
                'b',
                ['id', 'a_id', 'c_id'],
                ['id'],
                foreignKeys: [
                    self::foreignKey('fk_b_a', 'a_id', 'a', 'id'),
                    self::foreignKey('fk_b_c', 'c_id', 'c', 'id'),
                ],
                nullable: ['a_id', 'c_id'],
            ),
            self::table(
                'c',
                ['id', 'a_id'],
                ['id'],
                foreignKeys: [self::foreignKey('fk_c_a', 'a_id', 'a', 'id')],
                nullable: ['a_id'],
            ),
        ]);

        self::assertSame(['fk_b_a', 'fk_b_c', 'fk_c_a'], self::createdForeignKeys($sql));
        self::assertSame(['a', 'c', 'b'], self::createdTables($sql));
    }

    /** Two cycles through table b share no key, so one key of each is left out, the nullable one where there is one. */
    public function testCyclesSharingATableLeaveOutOneKeyEach(): void
    {
        $sql = $this->platform->getCreateTablesSQL([
            self::table('a', ['id', 'b_id'], ['id'], foreignKeys: [self::foreignKey('fk_a_b', 'b_id', 'b', 'id')]),
            self::table(
                'b',
                ['id', 'a_id', 'c_id'],
                ['id'],
                foreignKeys: [
                    self::foreignKey('fk_b_a', 'a_id', 'a', 'id'),
                    self::foreignKey('fk_b_c', 'c_id', 'c', 'id'),
                ],
                nullable: ['a_id'],
            ),
            self::table('c', ['id', 'b_id'], ['id'], foreignKeys: [self::foreignKey('fk_c_b', 'b_id', 'b', 'id')]),
        ]);

        self::assertSame(['fk_a_b', 'fk_c_b'], self::createdForeignKeys($sql));
    }

    /** The same key is left out whatever the order of the tables and of their keys. */
    public function testTheLeftOutKeyDoesNotDependOnOrder(): void
    {
        $tables = [
            self::table('a', ['id', 'b_id'], ['id'], foreignKeys: [self::foreignKey('fk_a_b', 'b_id', 'b', 'id')]),
            self::table(
                'b',
                ['id', 'a_id', 'c_id'],
                ['id'],
                foreignKeys: [
                    self::foreignKey('fk_b_a', 'a_id', 'a', 'id'),
                    self::foreignKey('fk_b_c', 'c_id', 'c', 'id'),
                ],
            ),
            self::table('c', ['id', 'b_id'], ['id'], foreignKeys: [self::foreignKey('fk_c_b', 'b_id', 'b', 'id')]),
        ];

        $expected = ['fk_b_a', 'fk_c_b'];

        self::assertSame($expected, self::createdForeignKeys($this->platform->getCreateTablesSQL($tables)));
        self::assertSame(
            $expected,
            self::createdForeignKeys($this->platform->getCreateTablesSQL(array_reverse($tables))),
        );
        self::assertSame(
            $expected,
            self::createdForeignKeys($this->platform->getCreateTablesSQL([$tables[1], $tables[2], $tables[0]])),
        );

        $reversedKeys = $tables[1]->edit()
            ->setForeignKeyConstraints(...array_reverse($tables[1]->getForeignKeys()))
            ->create();

        self::assertSame(
            $expected,
            self::createdForeignKeys($this->platform->getCreateTablesSQL([$tables[2], $reversedKeys, $tables[0]])),
        );
    }

    /** A key left out for its referential action breaks no cycle of its own; the plain cycle still loses one key. */
    public function testCycleNextToAKeyWithAnUnenforceableAction(): void
    {
        $sql = $this->platform->getCreateTablesSQL([
            self::table(
                'a',
                ['id', 'b_id', 'c_id'],
                ['id'],
                foreignKeys: [
                    self::foreignKey('fk_a_b', 'b_id', 'b', 'id'),
                    self::foreignKey('fk_a_c', 'c_id', 'c', 'id'),
                ],
            ),
            self::table(
                'b',
                ['id', 'a_id'],
                ['id'],
                foreignKeys: [self::foreignKey('fk_b_a', 'a_id', 'a', 'id')],
                nullable: ['a_id'],
            ),
            self::table(
                'c',
                ['id', 'a_id'],
                ['id'],
                foreignKeys: [
                    self::foreignKeyWithAction('fk_c_a', 'a_id', 'a', 'id', 'onDelete', ReferentialAction::CASCADE),
                ],
            ),
        ]);

        self::assertSame(['fk_a_b', 'fk_a_c'], self::createdForeignKeys($sql));
        self::assertSame(['b', 'c', 'a'], self::createdTables($sql));
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
     * @param list<non-empty-string>           $nullable          The columns that accept NULL.
     */
    private static function table(
        string $name,
        array $columns,
        array $primaryKey,
        array $indexes = [],
        array $uniqueConstraints = [],
        array $foreignKeys = [],
        array $nullable = [],
    ): Table {
        $editor = Table::editor()->setUnquotedName($name);

        foreach ($columns as $column) {
            $editor->addColumn(
                Column::editor()
                    ->setUnquotedName($column)
                    ->setTypeName(Types::INTEGER)
                    ->setNotNull(! in_array($column, $nullable, true))
                    ->create(),
            );
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

    /**
     * @param non-empty-string      $name
     * @param non-empty-string      $column
     * @param non-empty-string      $referencedTable
     * @param non-empty-string      $referencedColumn
     * @param 'onDelete'|'onUpdate' $event
     */
    private static function foreignKeyWithAction(
        string $name,
        string $column,
        string $referencedTable,
        string $referencedColumn,
        string $event,
        ReferentialAction $action,
    ): ForeignKeyConstraint {
        $editor = self::foreignKey($name, $column, $referencedTable, $referencedColumn)->edit();

        if ($event === 'onDelete') {
            return $editor->setOnDeleteAction($action)->create();
        }

        return $editor->setOnUpdateAction($action)->create();
    }

    private static function users(): Table
    {
        return self::table(
            'users',
            ['id', 'last_post_id'],
            ['id'],
            foreignKeys: [self::foreignKey('fk_users_last_post', 'last_post_id', 'posts', 'id')],
            nullable: ['last_post_id'],
        );
    }

    private static function posts(): Table
    {
        return self::table(
            'posts',
            ['id', 'author_id'],
            ['id'],
            foreignKeys: [self::foreignKey('fk_posts_author', 'author_id', 'users', 'id')],
        );
    }

    /**
     * The names of the foreign keys that the statements create, sorted.
     *
     * @param list<string> $sql
     *
     * @return list<string>
     */
    private static function createdForeignKeys(array $sql): array
    {
        preg_match_all('/CONSTRAINT (\w+) FOREIGN KEY/', implode("\n", $sql), $matches);

        $names = $matches[1];

        sort($names);

        return $names;
    }

    /**
     * The tables that the statements create, in order.
     *
     * @param list<string> $sql
     *
     * @return list<string>
     */
    private static function createdTables(array $sql): array
    {
        preg_match_all('/^CREATE TABLE (\w+) /m', implode("\n", $sql), $matches);

        return array_map('strval', $matches[1]);
    }
}
