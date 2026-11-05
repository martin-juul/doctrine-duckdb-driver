<?php

declare(strict_types=1);

/*
 * Upstream Doctrine tests that cannot pass on DuckDB 2.0, applied by
 * SkipListExtension. Keys are Class::method (every data set) or
 * Class::method#<data set name> (one data set). Each entry names a category
 * and gives a reason sentence. run.php fails on an entry that names no test,
 * and reports entries whose tests pass again.
 */

return [
    'categories' => [
        'savepoints' => [
            'savepoint partial rollback',
            'DuckDB 2.0 has no savepoints, so the driver flattens nested transactions and an inner rollback rolls back the whole transaction (R10).',
        ],
        'identity' => [
            'identity columns',
            'DuckDB 2.0 has no identity columns and no lastval(), so IDs come from sequences and lastInsertId() refuses (R14-R16).',
        ],
        'row-locks' => [
            'row locks',
            'DuckDB 2.0 has no row locks, so pessimistic locks are rejected (R9).',
        ],
        'early-fk' => [
            'early FK checks',
            'DuckDB 2.0 checks foreign keys when each statement runs and does not see references removed earlier in the same transaction, so ORM flushes that delete or re-key referenced rows fail (R28).',
        ],
        'omitted-fk-actions' => [
            'omitted referential actions',
            'DuckDB 2.0 enforces only NO ACTION and RESTRICT, so the platform leaves a foreign key that requests CASCADE, SET NULL or SET DEFAULT out of the DDL and the application enforces that relation (R13); introspection finds no such key.',
        ],
        'restrict-action' => [
            'RESTRICT stored as NO ACTION',
            'DuckDB 2.0 does not store a foreign key\'s referential action, so a RESTRICT key introspects as NO ACTION.',
        ],
        'unique-index-reference' => [
            'foreign keys to unique indexes',
            'DuckDB 2.0 accepts a foreign key only to a PRIMARY KEY or UNIQUE constraint, and the platform declares a unique constraint that no foreign key of the same batch or comparator rebuild references as a unique index (KTD11), so a later foreign key to it is refused.',
        ],
        'unsigned' => [
            'unsigned integers',
            'DBAL\'s unsigned option is a MySQL and SQLite declaration; the DuckDB platform ignores it as PostgreSQL\'s does, while the tests exempt only PostgreSQL and SQL Server by platform class.',
        ],
        'fk-cycles' => [
            'FK cycles',
            'DuckDB 2.0 creates a foreign key only together with its table, so foreign keys that form a cycle are rejected (R26).',
        ],
        'alter-without-comparator' => [
            'ALTER on referenced tables without the comparator',
            'DuckDB 2.0 cannot add or drop a foreign key on an existing table; only the comparator\'s table rebuild can (R12), so direct schema-manager calls are refused.',
        ],
        'lengths' => [
            'unenforced lengths',
            'DuckDB neither enforces nor reports string and binary lengths, so the platform omits them (KTD10).',
        ],
        'constraint-names' => [
            'constraint names not stored',
            'DuckDB 2.0 discards constraint names, so foreign keys introspect with generated names and named unique constraints are stored as unique indexes (KTD11).',
        ],
        'sequences' => [
            'sequence alteration',
            'DuckDB 2.0 cannot alter a sequence ("ALTER SEQUENCE option not yet supported").',
        ],
        'positional-parameters' => [
            'positional parameters only',
            'DuckDB prepares positional parameters, not :name placeholders; DBAL rewrites named parameters for queries it executes, but not for statements prepared directly.',
        ],
        'row-order' => [
            'unordered results',
            'The test expects rows in insertion order from a query without ORDER BY, which SQL does not promise; DuckDB\'s parallel hash joins return joined rows in an order that depends on earlier activity, so the test fails in the full suite and may pass alone.',
        ],
        'leftover-sequences' => [
            'ID sequences left behind',
            'IDs come from sequences (R14), and the test drops its tables itself but their sequences only on PostgreSQL, so creating the tables again fails on the existing sequence and every test of the class but the first that runs fails.',
        ],
        'embedded' => [
            'embedded engine',
            'DuckDB runs in-process: it has no users, passwords or hosts, and each in-memory connection has its own database, while the tests exempt only SQLite through platform-class checks.',
        ],
    ],

    'dbal' => [
        'Doctrine\DBAL\Tests\Functional\ConnectionTest::testTransactionNestingBehavior' => [
            'savepoints',
            'Commits the outer transaction after an inner rollback and expects the commit to succeed.',
        ],
        'Doctrine\DBAL\Tests\Functional\ConnectionTest::testTransactionNestingBehaviorWithSavepoints' => [
            'savepoints',
            'Commits the outer transaction after an inner rollback and expects the commit to succeed.',
        ],
        'Doctrine\DBAL\Tests\Functional\ConnectionTest::testTransactionNestingLevelIsResetOnReconnect' => [
            'embedded',
            'Reconnects and expects an earlier table to exist; only its SQLite branch moves the test to a file database.',
        ],
        'Doctrine\DBAL\Tests\Functional\LockMode\NoneTest::testLockModeNoneDoesNotBreakTransactionIsolation' => [
            'embedded',
            'Opens a second connection to the same database, which an in-memory DuckDB database cannot provide.',
        ],
        'Doctrine\DBAL\Tests\Functional\ExceptionTest::testInvalidUserName' => [
            'embedded',
            'Expects a wrong user name to fail the connection.',
        ],
        'Doctrine\DBAL\Tests\Functional\ExceptionTest::testInvalidPassword' => [
            'embedded',
            'Expects a wrong password to fail the connection.',
        ],
        'Doctrine\DBAL\Tests\Functional\ExceptionTest::testInvalidHost' => [
            'embedded',
            'Expects an unknown host to fail the connection.',
        ],
        'Doctrine\DBAL\Tests\Functional\ForeignKeyConstraintViolationsTest::testTransactionalViolatesDeferredConstraint' => [
            'alter-without-comparator',
            'setUp() adds the foreign key to an existing table with createForeignKey().',
        ],
        'Doctrine\DBAL\Tests\Functional\ForeignKeyConstraintViolationsTest::testTransactionalViolatesConstraint' => [
            'alter-without-comparator',
            'setUp() adds the foreign key to an existing table with createForeignKey().',
        ],
        'Doctrine\DBAL\Tests\Functional\ForeignKeyConstraintViolationsTest::testTransactionalViolatesDeferredConstraintWhileUsingTransactionNesting' => [
            'alter-without-comparator',
            'setUp() adds the foreign key to an existing table with createForeignKey().',
        ],
        'Doctrine\DBAL\Tests\Functional\ForeignKeyConstraintViolationsTest::testTransactionalViolatesConstraintWhileUsingTransactionNesting' => [
            'alter-without-comparator',
            'setUp() adds the foreign key to an existing table with createForeignKey().',
        ],
        'Doctrine\DBAL\Tests\Functional\ForeignKeyConstraintViolationsTest::testCommitViolatesDeferredConstraint' => [
            'alter-without-comparator',
            'setUp() adds the foreign key to an existing table with createForeignKey().',
        ],
        'Doctrine\DBAL\Tests\Functional\ForeignKeyConstraintViolationsTest::testInsertViolatesConstraint' => [
            'alter-without-comparator',
            'setUp() adds the foreign key to an existing table with createForeignKey().',
        ],
        'Doctrine\DBAL\Tests\Functional\ForeignKeyConstraintViolationsTest::testCommitViolatesDeferredConstraintWhileUsingTransactionNesting' => [
            'alter-without-comparator',
            'setUp() adds the foreign key to an existing table with createForeignKey().',
        ],
        'Doctrine\DBAL\Tests\Functional\ForeignKeyConstraintViolationsTest::testCommitViolatesConstraintWhileUsingTransactionNesting' => [
            'alter-without-comparator',
            'setUp() adds the foreign key to an existing table with createForeignKey().',
        ],
        'Doctrine\DBAL\Tests\Functional\Schema\SchemaManagerTest::testDropForeignKey' => [
            'alter-without-comparator',
            'Drops a foreign key from an existing table with dropForeignKey().',
        ],
        'Doctrine\DBAL\Tests\Functional\Query\QueryBuilderTest::testForUpdateOrdinary' => [
            'row-locks',
            'Selects with FOR UPDATE.',
        ],
        'Doctrine\DBAL\Tests\Functional\Query\QueryBuilderTest::testForUpdateSkipLockedWhenSupported' => [
            'row-locks',
            'Selects with FOR UPDATE SKIP LOCKED.',
        ],
        'Doctrine\DBAL\Tests\Functional\SQL\Builder\CreateAndDropSchemaObjectsSQLBuilderTest::testCreateAndDropTablesWithCircularForeignKeys' => [
            'fk-cycles',
            'Creates two tables that reference each other.',
        ],
        'Doctrine\DBAL\Tests\Functional\Schema\ForeignKeyConstraintTest::testOnUpdateIntrospection#CASCADE' => [
            'omitted-fk-actions',
            'Creates a foreign key with ON UPDATE CASCADE, which the platform leaves out, and expects to introspect it.',
        ],
        'Doctrine\DBAL\Tests\Functional\Schema\ForeignKeyConstraintTest::testOnUpdateIntrospection#SET NULL' => [
            'omitted-fk-actions',
            'Creates a foreign key with ON UPDATE SET NULL, which the platform leaves out, and expects to introspect it.',
        ],
        'Doctrine\DBAL\Tests\Functional\Schema\ForeignKeyConstraintTest::testOnUpdateIntrospection#SET DEFAULT' => [
            'omitted-fk-actions',
            'Creates a foreign key with ON UPDATE SET DEFAULT, which the platform leaves out, and expects to introspect it.',
        ],
        'Doctrine\DBAL\Tests\Functional\Schema\ForeignKeyConstraintTest::testOnUpdateIntrospection#RESTRICT' => [
            'restrict-action',
            'Expects ON UPDATE RESTRICT back from introspection.',
        ],
        'Doctrine\DBAL\Tests\Functional\Schema\ForeignKeyConstraintTest::testOnDeleteIntrospection#CASCADE' => [
            'omitted-fk-actions',
            'Creates a foreign key with ON DELETE CASCADE, which the platform leaves out, and expects to introspect it.',
        ],
        'Doctrine\DBAL\Tests\Functional\Schema\ForeignKeyConstraintTest::testOnDeleteIntrospection#SET NULL' => [
            'omitted-fk-actions',
            'Creates a foreign key with ON DELETE SET NULL, which the platform leaves out, and expects to introspect it.',
        ],
        'Doctrine\DBAL\Tests\Functional\Schema\ForeignKeyConstraintTest::testOnDeleteIntrospection#SET DEFAULT' => [
            'omitted-fk-actions',
            'Creates a foreign key with ON DELETE SET DEFAULT, which the platform leaves out, and expects to introspect it.',
        ],
        'Doctrine\DBAL\Tests\Functional\Schema\ForeignKeyConstraintTest::testOnDeleteIntrospection#RESTRICT' => [
            'restrict-action',
            'Expects ON DELETE RESTRICT back from introspection.',
        ],
        'Doctrine\DBAL\Tests\Functional\Schema\ForeignKeyConstraintTest::testColumnIntrospection' => [
            'constraint-names',
            'Expects the declared foreign-key names back from introspection.',
        ],
        'Doctrine\DBAL\Tests\Functional\Schema\SchemaManagerTest::testIntrospectTableUniqueConstraints' => [
            'constraint-names',
            'Expects a named unique constraint back from introspection.',
        ],
        'Doctrine\DBAL\Tests\Functional\Schema\SchemaManagerTest::testIntrospectTableUniqueConstraintsInNonDefaultSchema' => [
            'constraint-names',
            'Expects a unique constraint in another schema back from introspection, which finds a unique index.',
        ],
        'Doctrine\DBAL\Tests\Functional\Schema\SchemaManagerTest::testIntrospectAllTablesUniqueConstraintsInNonDefaultSchema' => [
            'constraint-names',
            'Expects a unique constraint in another schema back from introspection, which finds a unique index.',
        ],
        'Doctrine\DBAL\Tests\Functional\Schema\AlterTableTest::testReplaceForeignKeyConstraint' => [
            'unique-index-reference',
            'Creates articles with a unique constraint on sku on its own, then replaces a foreign key of orders with one to sku through alterTable(), which rebuilds only orders.',
        ],
        'Doctrine\DBAL\Tests\Functional\Schema\ColumnRenameTest::testRenameColumnInForeignKeyConstraint' => [
            'unique-index-reference',
            'Creates rename_column_referenced with a unique constraint on (c1, c2) on its own, then creates a table whose foreign key references those columns.',
        ],
        'Doctrine\DBAL\Tests\Functional\Platform\AlterColumnLengthChangeTest::testColumnLengthIsChanged' => [
            'lengths',
            'Expects a changed VARCHAR length back from introspection.',
        ],
        'Doctrine\DBAL\Tests\Functional\AutoIncrementColumnTest::testInsertIdentityValue' => [
            'identity',
            'Expects an explicit ID to advance the autoincrement counter, which a sequence does not do; the test exempts PostgreSQL by platform class for the same reason.',
        ],
        'Doctrine\DBAL\Tests\Functional\WriteTest::testLastInsertIdNotSupported' => [
            'identity',
            'Expects DBAL\'s internal IdentityColumnsNotSupported, while the driver throws its own exception that points to INSERT ... RETURNING (R16).',
        ],
        'Doctrine\DBAL\Tests\Functional\Schema\SchemaManagerTest::testAlterSequence' => [
            'sequences',
            'Changes the increment of an existing sequence.',
        ],
        'Doctrine\DBAL\Tests\Functional\StatementTest::testBindInvalidNamedParameter' => [
            'positional-parameters',
            'Prepares SELECT :foo directly and expects the error at execution; the test skips the other drivers without named parameters by name.',
        ],
    ],

    'orm' => [
        'Doctrine\Tests\ORM\Functional\Ticket\DDC832Test::testQuotedTableBasicRemove' => [
            'leftover-sequences',
            'tearDown() drops the INDEX, TREE_INDEX and LIKE tables, and their ID sequences only on PostgreSQL.',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\DDC832Test::testQuotedTableJoinedUpdate' => [
            'leftover-sequences',
            'tearDown() drops the INDEX, TREE_INDEX and LIKE tables, and their ID sequences only on PostgreSQL.',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\DDC832Test::testQuotedTableJoinedRemove' => [
            'leftover-sequences',
            'tearDown() drops the INDEX, TREE_INDEX and LIKE tables, and their ID sequences only on PostgreSQL.',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\DDC832Test::testQuotedTableJoinedChildUpdate' => [
            'leftover-sequences',
            'tearDown() drops the INDEX, TREE_INDEX and LIKE tables, and their ID sequences only on PostgreSQL.',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\DDC832Test::testQuotedTableJoinedChildRemove' => [
            'leftover-sequences',
            'tearDown() drops the INDEX, TREE_INDEX and LIKE tables, and their ID sequences only on PostgreSQL.',
        ],
        'Doctrine\Tests\ORM\Functional\DatabaseDriverTest::testLoadMetadataFromDatabase' => [
            'lengths',
            'Expects the VARCHAR length 200 back from introspection.',
        ],
        'Doctrine\Tests\ORM\Functional\DatabaseDriverTest::testLoadMetadataFromDatabaseDetail' => [
            'unsigned',
            'Expects the unsigned option of an integer column back from introspection.',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\DDC2252Test::testIssue' => [
            'early-fk',
            'Its flush sets the primary key of a referenced ddc2252_mch_account row to its own value, which DuckDB runs as a delete and an insert of a referenced row.',
        ],
        'Doctrine\Tests\ORM\Functional\EntityRepositoryTest::testFindFieldByMagicCall' => [
            'row-order',
            'Expects findByStatus() to return Guilherme before Alexander; the query left-joins cms_addresses for the eager association and has no ORDER BY.',
        ],
        'Doctrine\Tests\ORM\Functional\OneToManyBidirectionalAssociationTest::testEagerLoadsOneToManyAssociation' => [
            'row-order',
            'Expects the fetch-joined features in insertion order; the DQL join has no ORDER BY.',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\DDC2106Test::testDetachedEntityAsId' => [
            'identity',
            'Uses the IDENTITY generator strategy, which needs lastInsertId().',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\GH11341Test::testInstanceOf' => [
            'identity',
            'Uses the IDENTITY generator strategy, which needs lastInsertId().',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\GH5562Test::testCacheShouldBeUpdatedWhenAssociationChanges' => [
            'identity',
            'Uses the IDENTITY generator strategy, which needs lastInsertId().',
        ],
        'Doctrine\Tests\ORM\Functional\Locking\LockTest::testLockPessimisticRead' => [
            'row-locks',
            'Expects the PESSIMISTIC_READ lock SQL in the query log.',
        ],
        'Doctrine\Tests\ORM\Functional\Locking\LockTest::testLockPessimisticWrite' => [
            'row-locks',
            'Expects the PESSIMISTIC_WRITE lock SQL in the query log.',
        ],
        'Doctrine\Tests\ORM\Functional\Locking\LockTest::testRefreshWithLockPessimisticWrite' => [
            'row-locks',
            'Expects the PESSIMISTIC_WRITE lock SQL in the query log.',
        ],
        'Doctrine\Tests\ORM\Functional\BasicFunctionalTest::testBasicUnitsOfWorkWithOneToManyAssociation' => [
            'early-fk',
            'Its flush deletes or updates a referenced row after removing the references in the same transaction.',
        ],
        'Doctrine\Tests\ORM\Functional\BasicFunctionalTest::testOneToManyCascadeRemove' => [
            'early-fk',
            'Its flush deletes or updates a referenced row after removing the references in the same transaction.',
        ],
        'Doctrine\Tests\ORM\Functional\EntityRepositoryTest::testMatchingCriteriaNullAssocComparison' => [
            'early-fk',
            'Its flush deletes or updates a referenced row after removing the references in the same transaction.',
        ],
        'Doctrine\Tests\ORM\Functional\GH7877Test::testCrossReferenceWithApplicationGeneratedIdMayBeNotNullable' => [
            'early-fk',
            'Inserts a row that references itself, which DuckDB checks before the row exists.',
        ],
        'Doctrine\Tests\ORM\Functional\GH7877Test::testNullableForeignKeysMakeInsertOrderLessRelevant' => [
            'early-fk',
            'Inserts a row that references itself, which DuckDB checks before the row exists.',
        ],
        'Doctrine\Tests\ORM\Functional\GH7877Test::testSelfReferenceWithApplicationGeneratedIdMayBeNotNullable' => [
            'early-fk',
            'Inserts a row that references itself, which DuckDB checks before the row exists.',
        ],
        'Doctrine\Tests\ORM\Functional\ManyToManyBasicAssociationTest::testRemoveGroupWithUser' => [
            'early-fk',
            'Its flush deletes or updates a referenced row after removing the references in the same transaction.',
        ],
        'Doctrine\Tests\ORM\Functional\ManyToManyBasicAssociationTest::testRemoveUserWithManyGroups' => [
            'early-fk',
            'Its flush deletes or updates a referenced row after removing the references in the same transaction.',
        ],
        'Doctrine\Tests\ORM\Functional\OneToManyOrphanRemovalTest::testOrphanRemoval' => [
            'early-fk',
            'Its flush deletes or updates a referenced row after removing the references in the same transaction.',
        ],
        'Doctrine\Tests\ORM\Functional\OneToManySelfReferentialAssociationTest::testEagerLoadsOneToManyAssociation' => [
            'early-fk',
            'Leaves categories that reference each other; the suite\'s tearDown() then clears them with UPDATE ecommerce_categories SET parent_id = NULL, which DuckDB runs as a delete and an insert of rows that are still referenced.',
        ],
        'Doctrine\Tests\ORM\Functional\OneToManySelfReferentialAssociationTest::testLazyLoadsOneToManyAssociation' => [
            'early-fk',
            'Leaves categories that reference each other; the suite\'s tearDown() then clears them with UPDATE ecommerce_categories SET parent_id = NULL, which DuckDB runs as a delete and an insert of rows that are still referenced.',
        ],
        'Doctrine\Tests\ORM\Functional\OneToManySelfReferentialAssociationTest::testRemovesOneToManyAssociation' => [
            'early-fk',
            'Leaves categories that reference each other; the suite\'s tearDown() then clears them with UPDATE ecommerce_categories SET parent_id = NULL, which DuckDB runs as a delete and an insert of rows that are still referenced.',
        ],
        'Doctrine\Tests\ORM\Functional\OneToManySelfReferentialAssociationTest::testSavesAOneToManyAssociationWithCascadeSaveSet' => [
            'early-fk',
            'Leaves categories that reference each other; the suite\'s tearDown() then clears them with UPDATE ecommerce_categories SET parent_id = NULL, which DuckDB runs as a delete and an insert of rows that are still referenced.',
        ],
        'Doctrine\Tests\ORM\Functional\OneToOneOrphanRemovalTest::testOrphanRemoval' => [
            'early-fk',
            'Its flush deletes or updates a referenced row after removing the references in the same transaction.',
        ],
        'Doctrine\Tests\ORM\Functional\OneToOneOrphanRemovalTest::testOrphanRemovalWhenUnlink' => [
            'early-fk',
            'Its flush deletes or updates a referenced row after removing the references in the same transaction.',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\DDC1113Test::testIssue' => [
            'early-fk',
            'Its flush deletes or updates a referenced row after removing the references in the same transaction.',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\DDC117Test::testLoadOneToManyOfSourceEntityWithAssociationIdentifier' => [
            'early-fk',
            'Its flush deletes or updates a referenced row after removing the references in the same transaction.',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\DDC117Test::testOneToOneCascadeRemove' => [
            'early-fk',
            'Its flush deletes or updates a referenced row after removing the references in the same transaction.',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\DDC1181Test::testIssue' => [
            'early-fk',
            'Its flush deletes or updates a referenced row after removing the references in the same transaction.',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\DDC1193Test::testIssue' => [
            'early-fk',
            'Its flush deletes or updates a referenced row after removing the references in the same transaction.',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\DDC1250Test::testIssue' => [
            'early-fk',
            'Its flush deletes or updates a referenced row after removing the references in the same transaction.',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\DDC1306Test::testIssue' => [
            'early-fk',
            'Its flush deletes or updates a referenced row after removing the references in the same transaction.',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\DDC1666Test::testGivenOrphanRemovalOneToOneWhenReplacingThenNoUniqueConstraintError' => [
            'early-fk',
            'Its flush deletes or updates a referenced row after removing the references in the same transaction.',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\DDC1843Test::testCreateRetrieveUpdateDelete' => [
            'early-fk',
            'Its flush deletes or updates a referenced row after removing the references in the same transaction.',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\DDC1885Test::testCreateRetrieveUpdateDelete' => [
            'early-fk',
            'Its flush deletes or updates a referenced row after removing the references in the same transaction.',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\DDC2579Test::testIssue' => [
            'early-fk',
            'Its flush deletes or updates a referenced row after removing the references in the same transaction.',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\DDC353Test::testFailingCase' => [
            'early-fk',
            'Its flush deletes or updates a referenced row after removing the references in the same transaction.',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\DDC353Test::testWorkingCase' => [
            'early-fk',
            'Its flush deletes or updates a referenced row after removing the references in the same transaction.',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\DDC3785Test::testOwningValueObjectIdIsCorrectlyTransformedWhenRemovingOrphanedChildEntities' => [
            'early-fk',
            'Its flush deletes or updates a referenced row after removing the references in the same transaction.',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\DDC599Test::testCascadeRemoveOnInheritanceHierarchy' => [
            'early-fk',
            'Its flush deletes or updates a referenced row after removing the references in the same transaction.',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\GH10348Test::testTheORMRemovesReferencedEmployeeBeforeReferencingEmployee' => [
            'early-fk',
            'Its flush deletes or updates a referenced row after removing the references in the same transaction.',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\GH12403Test::testAssociationTargetCanSilentlyCompleteNativeLazyInitialization' => [
            'early-fk',
            'Its flush deletes or updates a referenced row after removing the references in the same transaction.',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\GH7062Test::testEntityWithAssociationKeyIdentityCanBeUpdated' => [
            'early-fk',
            'Its flush deletes or updates a referenced row after removing the references in the same transaction.',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\GH8443Test::testJoinRootEntityWithOnlyOneEntityInHierarchy' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\ValueConversionType\ManyToManyCompositeIdForeignKeyTest::testThatTheJoinTableRowsAreRemovedWhenRemovingTheAssociation' => [
            'early-fk',
            'Its flush deletes or updates a referenced row after removing the references in the same transaction.',
        ],
        'Doctrine\Tests\ORM\Functional\AdvancedDqlQueryTest::testAggregateWithHavingClause' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\AdvancedDqlQueryTest::testCommentsInDQL' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\AdvancedDqlQueryTest::testDeleteAs' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\AdvancedDqlQueryTest::testGroupByMultipleFields' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\AdvancedDqlQueryTest::testInSubselect' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\AdvancedDqlQueryTest::testIsNullAssociation' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\AdvancedDqlQueryTest::testOrderByGeneralCaseExpression' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\AdvancedDqlQueryTest::testOrderByResultVariableCollectionSize' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\AdvancedDqlQueryTest::testOrderBySimpleCaseExpression' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\AdvancedDqlQueryTest::testSelectSubselect' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\AdvancedDqlQueryTest::testUnnamedScalarResultsAreOneBased' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\AdvancedDqlQueryTest::testUpdateAs' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\CascadeRemoveOrderTest::testMany' => [
            'early-fk',
            'Its flush deletes or updates a referenced row after removing the references in the same transaction.',
        ],
        'Doctrine\Tests\ORM\Functional\CascadeRemoveOrderTest::testSingle' => [
            'early-fk',
            'Its flush deletes or updates a referenced row after removing the references in the same transaction.',
        ],
        'Doctrine\Tests\ORM\Functional\ClassTableInheritanceTest::testBulkUpdateIssueDDC368' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\ClassTableInheritanceTest::testBulkUpdateNonScalarParameterDDC1341' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\ClassTableInheritanceTest::testCRUD' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\ClassTableInheritanceTest::testDeleteJoinTableRecords' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\ClassTableInheritanceTest::testExistsSubclass' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\ClassTableInheritanceTest::testFindByAssociation' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\ClassTableInheritanceTest::testFindOnBaseClass' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\ClassTableInheritanceTest::testGetReferenceEntityWithSubclasses' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\ClassTableInheritanceTest::testGetSubClassManyToManyCollection' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\ClassTableInheritanceTest::testLazyLoading1' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\ClassTableInheritanceTest::testLazyLoading2' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\ClassTableInheritanceTest::testMatching' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\ClassTableInheritanceTest::testMultiLevelUpdateAndFind' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\ClassTableInheritanceTest::testQueryForInheritedSingleValuedAssociation' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\ClassTableInheritanceTest::testSelfReferencingManyToMany' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\ClassTableInheritanceTest::testSelfReferencingOneToOne' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\EntityListenersTest::testPostLoadListeners' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\EntityListenersTest::testPostPersistCalledAfterAllInsertsHaveBeenPerformedAndIdsHaveBeenAssigned' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\EntityListenersTest::testPostPersistListeners' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\EntityListenersTest::testPostRemoveCalledAfterAllRemovalsHaveBeenPerformed' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\EntityListenersTest::testPostRemoveListeners' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\EntityListenersTest::testPostUpdateListeners' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\EntityListenersTest::testPreFlushListeners' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\EntityListenersTest::testPrePersistListeners' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\EntityListenersTest::testPreRemoveListeners' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\EntityListenersTest::testPreUpdateListeners' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\ExtraLazyCollectionTest::testFirstOnEmptyCollectionWhenInitialized' => [
            'early-fk',
            'Its flush deletes or updates a referenced row after removing the references in the same transaction.',
        ],
        'Doctrine\Tests\ORM\Functional\ExtraLazyCollectionTest::testFirstOnEmptyCollectionWhenNotInitialized' => [
            'early-fk',
            'Its flush deletes or updates a referenced row after removing the references in the same transaction.',
        ],
        'Doctrine\Tests\ORM\Functional\GH11345Test::testUpdateSqlStatementsAreIssuedInAlphabeticOrderByEntityClass' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\GH11345Test::testUpdateSqlStatementsAreIssuedInAlphabeticOrderByEntityIdForSameClassName' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\GH8011Test::testOrderWithArithmeticExpressionWithLiteralAndResultVariable' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\GH8011Test::testOrderWithArithmeticExpressionWithLiteralAndResultVariable2' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\GH8011Test::testOrderWithArithmeticExpressionWithLiteralAndResultVariableUsingHiddenResultVariable' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\GH8011Test::testOrderWithArithmeticExpressionWithLiteralAndSingleValuedPathExpression' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\GH8011Test::testOrderWithArithmeticExpressionWithLiteralAndSingleValuedPathExpression2' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\GH8011Test::testOrderWithArithmeticExpressionWithResultVariableAndLiteral' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\GH8011Test::testOrderWithArithmeticExpressionWithResultVariableAndLiteral2' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\GH8011Test::testOrderWithArithmeticExpressionWithResultVariableAndSingleValuedPathExpression' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\GH8011Test::testOrderWithArithmeticExpressionWithResultVariableAndSingleValuedPathExpression2' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\GH8011Test::testOrderWithArithmeticExpressionWithSingleValuedPathExpression' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\GH8011Test::testOrderWithArithmeticExpressionWithSingleValuedPathExpressionAndLiteral' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\GH8011Test::testOrderWithArithmeticExpressionWithSingleValuedPathExpressionAndResultVariable' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\NativeQueryTest::testAbstractClassInSingleTableInheritanceSchemaWithRSMBuilderThrowsException' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\NativeQueryTest::testBasicNativeQuery' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\NativeQueryTest::testBasicNativeQueryWithMetaResult' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\NativeQueryTest::testConcreteClassInSingleTableInheritanceSchemaWithRSMBuilderIsFine' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\NativeQueryTest::testFluentInterface' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\NativeQueryTest::testGenerateSelectClauseCustomRenames' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\NativeQueryTest::testGenerateSelectClauseIncrement' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\NativeQueryTest::testGenerateSelectClauseNoRenameSingleEntity' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\NativeQueryTest::testGenerateSelectClauseRenameTableAlias' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\NativeQueryTest::testGenerateSelectClauseToString' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\NativeQueryTest::testGenerateSelectClauseWithCustomTypeUsingAddFieldResult' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\NativeQueryTest::testGenerateSelectClauseWithCustomTypeUsingEntityFromClassMetadata' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\NativeQueryTest::testGenerateSelectClauseWithDiscriminatorColumn' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\NativeQueryTest::testJoinedOneToManyNativeQuery' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\NativeQueryTest::testJoinedOneToManyNativeQueryWithRSMBuilder' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\NativeQueryTest::testJoinedOneToOneNativeQuery' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\NativeQueryTest::testJoinedOneToOneNativeQueryWithRSMBuilder' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\NativeQueryTest::testMappingAsDto' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\NativeQueryTest::testNativeQueryWithArrayParameter' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\NativeQueryTest::testRSMBuilderThrowsExceptionOnColumnConflict' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\NativeQueryTest::testUnknownParentAliasThrowsException' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\PaginationTest::testCloneQuery' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\PaginationTest::testCountComplexWithOutputWalker' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\PaginationTest::testCountComplexWithoutOutputWalker' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\PaginationTest::testCountQueryStripsParametersInSelect' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\PaginationTest::testCountSimpleWithoutJoin' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\PaginationTest::testCountWithComplexScalarOrderBy' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\PaginationTest::testCountWithCountSubqueryInWhereClauseWithOutputWalker' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\PaginationTest::testCountWithFetchJoin' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\PaginationTest::testCursorPaginatorCannotBuildCursorFromFieldOfJoinedCollection' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\PaginationTest::testCursorPaginatorOrderedByFieldOfJoinedEntity' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\PaginationTest::testCursorPaginatorOrderedByFieldOfJoinedEntityThatIsNotSelected' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\PaginationTest::testCursorPaginatorOrderedByFieldOfJoinedEntityWithNonEntityResults' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\PaginationTest::testCursorPaginatorOrderedByFieldOfNestedJoinedEntity' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\PaginationTest::testCursorPaginatorWithFetchJoinStripsParametersOnlyUsedInSelect' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\PaginationTest::testCustomIdTypeWithoutOutputWalker' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\PaginationTest::testDetectOutputWalker' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\PaginationTest::testDifferentResultLengthsDoNotRequireExtraQueryCacheEntries' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\PaginationTest::testIterateComplexWithOutputWalker' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\PaginationTest::testIterateSimpleWithOutputWalkerWithoutJoinWithComplexOrder' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\PaginationTest::testIterateSimpleWithOutputWalkerWithoutJoinWithComplexOrderAndLimit' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\PaginationTest::testIterateSimpleWithOutputWalkerWithoutJoinWithComplexOrderAndLimitAndOffset' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\PaginationTest::testIterateSimpleWithoutJoin' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\PaginationTest::testIterateSimpleWithoutJoinWithOrder' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\PaginationTest::testIterateSimpleWithoutJoinWithOrderAndLimit' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\PaginationTest::testIterateSimpleWithoutJoinWithOrderAndLimitAndOffset' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\PaginationTest::testIterateWithCountSubqueryInWhereClause' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\PaginationTest::testIterateWithFetchJoin' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\PaginationTest::testIterateWithFetchJoinOneToManyWithOrderByColumnFromBoth' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\PaginationTest::testIterateWithFetchJoinOneToManyWithOrderByColumnFromBothWithLimitWithOutputWalker' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\PaginationTest::testIterateWithFetchJoinOneToManyWithOrderByColumnFromBothWithLimitWithoutOutputWalker' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\PaginationTest::testIterateWithFetchJoinOneToManyWithOrderByColumnFromJoined' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\PaginationTest::testIterateWithFetchJoinOneToManyWithOrderByColumnFromJoinedWithLimitWithOutputWalker' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\PaginationTest::testIterateWithFetchJoinOneToManyWithOrderByColumnFromJoinedWithLimitWithoutOutputWalker' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\PaginationTest::testIterateWithFetchJoinOneToManyWithOrderByColumnFromRoot' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\PaginationTest::testIterateWithFetchJoinOneToManyWithOrderByColumnFromRootWithLimit' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\PaginationTest::testIterateWithFetchJoinOneToManyWithOrderByColumnFromRootWithLimitAndOffset' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\PaginationTest::testIterateWithFetchJoinWithOrder' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\PaginationTest::testIterateWithFetchJoinWithOrderAndLimit' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\PaginationTest::testIterateWithFetchJoinWithOrderAndLimitAndOffset' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\PaginationTest::testIterateWithFetchJoinWithOrderByColumnFromJoined' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\PaginationTest::testIterateWithFetchJoinWithOrderByColumnFromJoinedWithLimit' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\PaginationTest::testIterateWithFetchJoinWithOrderByColumnFromJoinedWithLimitAndOffset' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\PaginationTest::testIterateWithOutputWalkersWithFetchJoinWithComplexOrderByReferencingJoined' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\PaginationTest::testIterateWithOutputWalkersWithFetchJoinWithComplexOrderByReferencingJoinedWithLimit' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\PaginationTest::testIterateWithOutputWalkersWithFetchJoinWithComplexOrderByReferencingJoinedWithLimitAndOffset' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\PaginationTest::testIterateWithOutputWalkersWithFetchJoinWithComplexOrderByReferencingJoinedWithLimitAndOffsetWithInheritanceType' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\PaginationTest::testIterateWithOutputWalkersWithRegularJoinWithComplexOrderByReferencingJoined' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\PaginationTest::testIterateWithOutputWalkersWithRegularJoinWithComplexOrderByReferencingJoinedWithLimit' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\PaginationTest::testIterateWithOutputWalkersWithRegularJoinWithComplexOrderByReferencingJoinedWithLimitAndOffset' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\PaginationTest::testIterateWithRegularJoinWithOrderByColumnFromJoined' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\PaginationTest::testIterateWithRegularJoinWithOrderByColumnFromJoinedWithLimit' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\PaginationTest::testIterateWithRegularJoinWithOrderByColumnFromJoinedWithLimitAndOffset' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\PaginationTest::testJoinedClassTableInheritance' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\PaginationTest::testOffsetPaginatorIsReusableAcrossQueriesAndPages' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\PaginationTest::testOffsetPaginatorRejectsANonWindowPosition' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\PaginationTest::testOffsetPaginatorReturnsFirstPage' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\PaginationTest::testOffsetPaginatorReturnsLastPage' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\PaginationTest::testOffsetPaginatorWithFetchJoin' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\PaginationTest::testOffsetPaginatorWithFromPageNumberAndSize' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\PaginationTest::testPageItemsAreAListEvenForIndexByQueries' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\PaginationTest::testPaginationWithColumnAttributeNameDifference' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\PaginationTest::testPaginationWithSubSelectOrderByExpression' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\PaginationTest::testQueryWalkerIsKept' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\ParserResultSerializationTest::testSerializeParserResultForQueryWithSqlOutputWalker' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\ParserResultSerializationTest::testSerializeParserResultForQueryWithSqlWalker' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\ParserResultSerializationTest::testSymfony44ProvidedData' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\ParserResultSerializationTest::testUnserializeSingleSelectResult' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\QueryDqlFunctionTest::testAggregateAvg' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\QueryDqlFunctionTest::testAggregateCount' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\QueryDqlFunctionTest::testAggregateMax' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\QueryDqlFunctionTest::testAggregateMin' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\QueryDqlFunctionTest::testAggregateSum' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\QueryDqlFunctionTest::testBitAndComparison' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\QueryDqlFunctionTest::testBitOrComparison' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\QueryDqlFunctionTest::testConcatFunction' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\QueryDqlFunctionTest::testDateAdd' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\QueryDqlFunctionTest::testDateAddWithColumnInterval' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\QueryDqlFunctionTest::testDateDiff' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\QueryDqlFunctionTest::testDateSub' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\QueryDqlFunctionTest::testDateSubWithColumnInterval' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\QueryDqlFunctionTest::testFunctionAbs' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\QueryDqlFunctionTest::testFunctionConcat' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\QueryDqlFunctionTest::testFunctionLength' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\QueryDqlFunctionTest::testFunctionLocate' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\QueryDqlFunctionTest::testFunctionLower' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\QueryDqlFunctionTest::testFunctionMod' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\QueryDqlFunctionTest::testFunctionSqrt' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\QueryDqlFunctionTest::testFunctionSubstring' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\QueryDqlFunctionTest::testFunctionTrim' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\QueryDqlFunctionTest::testFunctionUpper' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\QueryDqlFunctionTest::testInArithmeticExpression1' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\QueryDqlFunctionTest::testInArithmeticExpression2' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\QueryDqlFunctionTest::testOperatorAdd' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\QueryDqlFunctionTest::testOperatorDiv' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\QueryDqlFunctionTest::testOperatorMultiply' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\QueryDqlFunctionTest::testOperatorSub' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\ReadonlyPropertiesTest::testEntityWithEagerManyToOne' => [
            'identity',
            'Uses the IDENTITY generator strategy, which needs lastInsertId().',
        ],
        'Doctrine\Tests\ORM\Functional\ReadonlyPropertiesTest::testEntityWithLazyManyToOne' => [
            'identity',
            'Uses the IDENTITY generator strategy, which needs lastInsertId().',
        ],
        'Doctrine\Tests\ORM\Functional\ReadonlyPropertiesTest::testEntityWithManyToMany' => [
            'identity',
            'Uses the IDENTITY generator strategy, which needs lastInsertId().',
        ],
        'Doctrine\Tests\ORM\Functional\ReadonlyPropertiesTest::testSimpleEntity' => [
            'identity',
            'Uses the IDENTITY generator strategy, which needs lastInsertId().',
        ],
        'Doctrine\Tests\ORM\Functional\ReferenceProxyTest::testAccessMetatadaForProxy' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\ReferenceProxyTest::testCloneProxy' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\ReferenceProxyTest::testCloneProxyWithResetId' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\ReferenceProxyTest::testCommonPersistenceProxy' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\ReferenceProxyTest::testDoNotInitializeProxyOnGettingTheIdentifier' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\ReferenceProxyTest::testDoNotInitializeProxyOnGettingTheIdentifierAndReturnTheRightType' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\ReferenceProxyTest::testDoNotInitializeProxyOnGettingTheIdentifierDDC1625' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\ReferenceProxyTest::testInitializeChangeAndFlushProxy' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\ReferenceProxyTest::testInitializeProxy' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\ReferenceProxyTest::testInitializeProxyOnGettingSomethingOtherThanTheIdentifier' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\ReferenceProxyTest::testLazyLoadsFieldValuesFromDatabase' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\ReferenceProxyTest::testReferenceFind' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\ReferenceProxyTest::testWakeupOnProxy' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\SQLFilterTest::testConfigureFilter' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\SQLFilterTest::testEntityManagerDisableFilter' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\SQLFilterTest::testEntityManagerEnableFilter' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\SQLFilterTest::testEntityManagerEnabledFilters' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\SQLFilterTest::testEntityManagerGetFilter' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\SQLFilterTest::testEntityManagerIsFilterEnabled' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\SQLFilterTest::testJoinSubclassPersisterFilterOnlyOnRootTableWhenFetchingRootEntity' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\SQLFilterTest::testJoinSubclassPersisterFilterOnlyOnRootTableWhenFetchingSubEntity' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\SQLFilterTest::testManyToManyExtraLazyContainsWithFilter' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\SQLFilterTest::testManyToManyExtraLazyContainsWithFilterOnCTI' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\SQLFilterTest::testManyToManyExtraLazyContainsWithFilterOnSTI' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\SQLFilterTest::testManyToManyExtraLazyCountWithFilter' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\SQLFilterTest::testManyToManyExtraLazyCountWithFilterOnCTI' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\SQLFilterTest::testManyToManyExtraLazyCountWithFilterOnSTI' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\SQLFilterTest::testManyToManyExtraLazySliceWithFilter' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\SQLFilterTest::testManyToManyExtraLazySliceWithFilterOnCTI' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\SQLFilterTest::testManyToManyExtraLazySliceWithFilterOnSTI' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\SQLFilterTest::testManyToManyFilter' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\SQLFilterTest::testOneToManyExtraLazyContainsWithFilter' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\SQLFilterTest::testOneToManyExtraLazyContainsWithFilterOnCTI' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\SQLFilterTest::testOneToManyExtraLazyContainsWithFilterOnSTI' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\SQLFilterTest::testOneToManyExtraLazyCountWithFilter' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\SQLFilterTest::testOneToManyExtraLazyCountWithFilterOnCTI' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\SQLFilterTest::testOneToManyExtraLazyCountWithFilterOnSTI' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\SQLFilterTest::testOneToManyExtraLazySliceWithFilter' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\SQLFilterTest::testOneToManyExtraLazySliceWithFilterOnCTI' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\SQLFilterTest::testOneToManyExtraLazySliceWithFilterOnSTI' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\SQLFilterTest::testOneToOneInverseSideWithFilter' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\SQLFilterTest::testQueryCacheDependsOnFilters' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\SQLFilterTest::testQueryGenerationDependsOnFilters' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\SQLFilterTest::testRepositoryFind' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\SQLFilterTest::testRepositoryFindAll' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\SQLFilterTest::testRepositoryFindBy' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\SQLFilterTest::testRepositoryFindByX' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\SQLFilterTest::testRepositoryFindOneBy' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\SQLFilterTest::testRepositoryFindOneByX' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\SQLFilterTest::testRetrieveListAsSingleThrowsException' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\SQLFilterTest::testRetrieveSingleAsListThrowsException' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\SQLFilterTest::testSQLFilterAddConstraint' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\SQLFilterTest::testSQLFilterGetConnection' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\SQLFilterTest::testSQLFilterGetSetParameter' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\SQLFilterTest::testSQLFilterSetArrayParameterInfersType' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\SQLFilterTest::testSQLFilterSetParameterInfersType' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\SQLFilterTest::testSQLFilterToString' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\SQLFilterTest::testSingleTableInheritanceFilterOnlyOnRootTableWhenFetchingRootEntity' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\SQLFilterTest::testSingleTableInheritanceFilterOnlyOnRootTableWhenFetchingSubEntity' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\SQLFilterTest::testToOneFilter' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\SQLFilterTest::testWhereFilter' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\SQLFilterTest::testWhereOrFilter' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\SchemaTool\CompanySchemaTest::testGeneratedSchema' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\SchemaTool\DDC214Test::testCompanyModel' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\SingleTableInheritanceTest::testChildClassLifecycleRemove' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\SingleTableInheritanceTest::testChildClassLifecycleUpdate' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\SingleTableInheritanceTest::testDeleteByBaseClassCondition' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\SingleTableInheritanceTest::testDeleteByChildClassCondition' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\SingleTableInheritanceTest::testDeleteJoinTableRecords' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\SingleTableInheritanceTest::testEagerLoadInheritanceHierarchy' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\SingleTableInheritanceTest::testFindAllForAbstractBaseClass' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\SingleTableInheritanceTest::testFindAllForChildClass' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\SingleTableInheritanceTest::testFindByAssociation' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\SingleTableInheritanceTest::testFindForAbstractBaseClass' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\SingleTableInheritanceTest::testGetReferenceEntityWithSubclasses' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\SingleTableInheritanceTest::testInheritanceMatching' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\SingleTableInheritanceTest::testMatchingNonObjectOnAssocationThrowsException' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\SingleTableInheritanceTest::testPersistChildOfBaseClass' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\SingleTableInheritanceTest::testPersistDeepChildOfBaseClass' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\SingleTableInheritanceTest::testQueryBaseClassWithJoin' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\SingleTableInheritanceTest::testQueryChildClassWithCondition' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\SingleTableInheritanceTest::testQueryForAbstractBaseClass' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\SingleTableInheritanceTest::testQueryForChildClass' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\SingleTableInheritanceTest::testQueryScalarWithDiscriminatorValue' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\SingleTableInheritanceTest::testUpdateBaseClassWithCondition' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\SingleTableInheritanceTest::testUpdateChildClassWithCondition' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\DDC1041Test::testGrabWrongSubtypeReturnsNull' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\DDC163Test::testQueryWithOrConditionUsingTwoRelationOnSameEntity' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\DDC168Test::testJoinedSubclassPersisterRequiresSpecificOrderOfMetadataReflFieldsArray' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\DDC1995Test::testIssue' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\DDC1995Test::testQueryCache' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\DDC2090Test::testIssue' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\DDC2775Test::testIssueCascadeRemove' => [
            'early-fk',
            'Its flush deletes or updates a referenced row after removing the references in the same transaction.',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\DDC331Test::testSelectFieldOnRootEntity' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\DDC3597Test::testSaveImageEntity' => [
            'identity',
            'Uses the IDENTITY generator strategy, which needs lastInsertId().',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\DDC3719Test::testCriteriaOnNotOwningSide' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\DDC933Test::testLockCTIClass' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\GH10531Test::testDeletes' => [
            'early-fk',
            'Its flush deletes or updates a referenced row after removing the references in the same transaction.',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\GH10532Test::testDeletes' => [
            'early-fk',
            'Its flush deletes or updates a referenced row after removing the references in the same transaction.',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\GH10912Test::testIssue' => [
            'early-fk',
            'Its flush deletes or updates a referenced row after removing the references in the same transaction.',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\GH10913Test::testExample1' => [
            'early-fk',
            'Its flush deletes or updates a referenced row after removing the references in the same transaction.',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\GH10913Test::testExample2' => [
            'early-fk',
            'Its flush deletes or updates a referenced row after removing the references in the same transaction.',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\GH10913Test::testExample3' => [
            'early-fk',
            'Its flush deletes or updates a referenced row after removing the references in the same transaction.',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\GH10913Test::testExample4' => [
            'early-fk',
            'Its flush deletes or updates a referenced row after removing the references in the same transaction.',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\GH5742Test::testManyToManyCollectionUpdateBeforeRemoval' => [
            'early-fk',
            'Its flush deletes or updates a referenced row after removing the references in the same transaction.',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\GH5742Test::testUpdateOneToOneToNewEntityBeforePreviousEntityCanBeRemoved' => [
            'early-fk',
            'Its flush deletes or updates a referenced row after removing the references in the same transaction.',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\GH5998Test::testIssue' => [
            'early-fk',
            'Its flush deletes or updates a referenced row after removing the references in the same transaction.',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\GH7039Test::testCountWithInstanceOfParameter' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\GH8443Test::testJoinRootEntityWithForcePartialLoad' => [
            'fk-cycles',
            'Uses the company models, whose tables company_organizations and company_events reference each other.',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\GH9192Test::testIssue' => [
            'early-fk',
            'Its flush deletes or updates a referenced row after removing the references in the same transaction.',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\DDC1461Test::testChangeDetectionDeferredExplicit' => [
            'fk-cycles',
            'Its schema has foreign keys that form a cycle between tables DDC1461TwitterAccount, DDC1461User.',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\GH6499Test::testIssue' => [
            'fk-cycles',
            'Its schema has foreign keys that form a cycle between tables GH6499A, GH6499B.',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\GH6499Test::testIssueReversed' => [
            'fk-cycles',
            'Its schema has foreign keys that form a cycle between tables GH6499A, GH6499B.',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\GH7006Test::testIssue' => [
            'fk-cycles',
            'Its schema has foreign keys that form a cycle between tables GH7006Book, GH7006PCT.',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\GH7180Test::testIssue' => [
            'fk-cycles',
            'Its schema has foreign keys that form a cycle between tables GH7180D, GH7180E, GH7180F.',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\GH7180Test::testIssue3NodeCycle' => [
            'fk-cycles',
            'Its schema has foreign keys that form a cycle between tables GH7180D, GH7180E, GH7180F.',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\GH7259Test::testPersistFileAfterVersion' => [
            'fk-cycles',
            'Its schema has foreign keys that form a cycle between tables GH7259Space, GH7259File.',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\GH7259Test::testPersistFileBeforeVersion' => [
            'fk-cycles',
            'Its schema has foreign keys that form a cycle between tables GH7259Space, GH7259File.',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\GH9579Test::testIssue' => [
            'fk-cycles',
            'Its schema has foreign keys that form a cycle between tables GH9579_containers, GH9579_items.',
        ],
    ],
];
