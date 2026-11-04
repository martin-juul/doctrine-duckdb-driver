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
        'fk-actions' => [
            'FK referential actions',
            'DuckDB 2.0 supports only NO ACTION and RESTRICT and does not store the action, so other actions are rejected (R13) and RESTRICT introspects as NO ACTION.',
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
            'fk-actions',
            'Creates a foreign key with ON UPDATE CASCADE.',
        ],
        'Doctrine\DBAL\Tests\Functional\Schema\ForeignKeyConstraintTest::testOnUpdateIntrospection#SET NULL' => [
            'fk-actions',
            'Creates a foreign key with ON UPDATE SET NULL.',
        ],
        'Doctrine\DBAL\Tests\Functional\Schema\ForeignKeyConstraintTest::testOnUpdateIntrospection#SET DEFAULT' => [
            'fk-actions',
            'Creates a foreign key with ON UPDATE SET DEFAULT.',
        ],
        'Doctrine\DBAL\Tests\Functional\Schema\ForeignKeyConstraintTest::testOnUpdateIntrospection#RESTRICT' => [
            'fk-actions',
            'Expects ON UPDATE RESTRICT back from introspection.',
        ],
        'Doctrine\DBAL\Tests\Functional\Schema\ForeignKeyConstraintTest::testOnDeleteIntrospection#CASCADE' => [
            'fk-actions',
            'Creates a foreign key with ON DELETE CASCADE.',
        ],
        'Doctrine\DBAL\Tests\Functional\Schema\ForeignKeyConstraintTest::testOnDeleteIntrospection#SET NULL' => [
            'fk-actions',
            'Creates a foreign key with ON DELETE SET NULL.',
        ],
        'Doctrine\DBAL\Tests\Functional\Schema\ForeignKeyConstraintTest::testOnDeleteIntrospection#SET DEFAULT' => [
            'fk-actions',
            'Creates a foreign key with ON DELETE SET DEFAULT.',
        ],
        'Doctrine\DBAL\Tests\Functional\Schema\ForeignKeyConstraintTest::testOnDeleteIntrospection#RESTRICT' => [
            'fk-actions',
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
            'early-fk',
            'Its flush deletes or updates a referenced row after removing the references in the same transaction.',
        ],
        'Doctrine\Tests\ORM\Functional\ValueConversionType\ManyToManyCompositeIdForeignKeyTest::testThatTheJoinTableRowsAreRemovedWhenRemovingTheAssociation' => [
            'early-fk',
            'Its flush deletes or updates a referenced row after removing the references in the same transaction.',
        ],
        'Doctrine\Tests\ORM\Functional\AdvancedDqlQueryTest::testAggregateWithHavingClause' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\AdvancedDqlQueryTest::testCommentsInDQL' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\AdvancedDqlQueryTest::testDeleteAs' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\AdvancedDqlQueryTest::testGroupByMultipleFields' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\AdvancedDqlQueryTest::testInSubselect' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\AdvancedDqlQueryTest::testIsNullAssociation' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\AdvancedDqlQueryTest::testOrderByGeneralCaseExpression' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\AdvancedDqlQueryTest::testOrderByResultVariableCollectionSize' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\AdvancedDqlQueryTest::testOrderBySimpleCaseExpression' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\AdvancedDqlQueryTest::testSelectSubselect' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\AdvancedDqlQueryTest::testUnnamedScalarResultsAreOneBased' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\AdvancedDqlQueryTest::testUpdateAs' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\CascadeRemoveOrderTest::testMany' => [
            'fk-actions',
            'Uses a many-to-many association whose default join columns ORM declares with ON DELETE CASCADE (CascadeRemoveOrderEntityO).',
        ],
        'Doctrine\Tests\ORM\Functional\CascadeRemoveOrderTest::testSingle' => [
            'fk-actions',
            'Uses a many-to-many association whose default join columns ORM declares with ON DELETE CASCADE (CascadeRemoveOrderEntityO).',
        ],
        'Doctrine\Tests\ORM\Functional\ClassTableInheritanceSecondTest::testManyToManyToCTIHierarchy' => [
            'fk-actions',
            'Uses a many-to-many association whose default join columns ORM declares with ON DELETE CASCADE (cti_children).',
        ],
        'Doctrine\Tests\ORM\Functional\ClassTableInheritanceSecondTest::testOneToOneAssocToBaseTypeBidirectional' => [
            'fk-actions',
            'Uses a many-to-many association whose default join columns ORM declares with ON DELETE CASCADE (cti_children).',
        ],
        'Doctrine\Tests\ORM\Functional\ClassTableInheritanceTest::testBulkUpdateIssueDDC368' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\ClassTableInheritanceTest::testBulkUpdateNonScalarParameterDDC1341' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\ClassTableInheritanceTest::testCRUD' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\ClassTableInheritanceTest::testDeleteJoinTableRecords' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\ClassTableInheritanceTest::testExistsSubclass' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\ClassTableInheritanceTest::testFindByAssociation' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\ClassTableInheritanceTest::testFindOnBaseClass' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\ClassTableInheritanceTest::testGetReferenceEntityWithSubclasses' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\ClassTableInheritanceTest::testGetSubClassManyToManyCollection' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\ClassTableInheritanceTest::testLazyLoading1' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\ClassTableInheritanceTest::testLazyLoading2' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\ClassTableInheritanceTest::testMatching' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\ClassTableInheritanceTest::testMultiLevelUpdateAndFind' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\ClassTableInheritanceTest::testQueryForInheritedSingleValuedAssociation' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\ClassTableInheritanceTest::testSelfReferencingManyToMany' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\ClassTableInheritanceTest::testSelfReferencingOneToOne' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\EntityListenersTest::testPostLoadListeners' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\EntityListenersTest::testPostPersistCalledAfterAllInsertsHaveBeenPerformedAndIdsHaveBeenAssigned' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\EntityListenersTest::testPostPersistListeners' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\EntityListenersTest::testPostRemoveCalledAfterAllRemovalsHaveBeenPerformed' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\EntityListenersTest::testPostRemoveListeners' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\EntityListenersTest::testPostUpdateListeners' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\EntityListenersTest::testPreFlushListeners' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\EntityListenersTest::testPrePersistListeners' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\EntityListenersTest::testPreRemoveListeners' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\EntityListenersTest::testPreUpdateListeners' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\EnumTest::testEnumCollectionMatchingOnManyToMany' => [
            'fk-actions',
            'Uses a many-to-many association whose default join columns ORM declares with ON DELETE CASCADE (bookwithgenre_bookcategory).',
        ],
        'Doctrine\Tests\ORM\Functional\EnumTest::testEnumCollectionMatchingOnOneToMany' => [
            'fk-actions',
            'Uses a many-to-many association whose default join columns ORM declares with ON DELETE CASCADE (bookwithgenre_bookcategory).',
        ],
        'Doctrine\Tests\ORM\Functional\ExtraLazyCollectionTest::testContainsKeyIndexByManyToMany' => [
            'fk-actions',
            'Uses JOINED inheritance, for which ORM generates ON DELETE CASCADE from DDC2504ChildClass to its root table.',
        ],
        'Doctrine\Tests\ORM\Functional\ExtraLazyCollectionTest::testContainsKeyIndexByManyToManyNonOwning' => [
            'fk-actions',
            'Uses JOINED inheritance, for which ORM generates ON DELETE CASCADE from DDC2504ChildClass to its root table.',
        ],
        'Doctrine\Tests\ORM\Functional\ExtraLazyCollectionTest::testContainsKeyIndexByOneToMany' => [
            'fk-actions',
            'Uses JOINED inheritance, for which ORM generates ON DELETE CASCADE from DDC2504ChildClass to its root table.',
        ],
        'Doctrine\Tests\ORM\Functional\ExtraLazyCollectionTest::testContainsKeyIndexByOneToManyJoinedInheritance' => [
            'fk-actions',
            'Uses JOINED inheritance, for which ORM generates ON DELETE CASCADE from DDC2504ChildClass to its root table.',
        ],
        'Doctrine\Tests\ORM\Functional\ExtraLazyCollectionTest::testContainsKeyIndexByWithPkManyToMany' => [
            'fk-actions',
            'Uses JOINED inheritance, for which ORM generates ON DELETE CASCADE from DDC2504ChildClass to its root table.',
        ],
        'Doctrine\Tests\ORM\Functional\ExtraLazyCollectionTest::testContainsKeyIndexByWithPkManyToManyNonOwning' => [
            'fk-actions',
            'Uses JOINED inheritance, for which ORM generates ON DELETE CASCADE from DDC2504ChildClass to its root table.',
        ],
        'Doctrine\Tests\ORM\Functional\ExtraLazyCollectionTest::testContainsKeyNonExistentIndexByManyToMany' => [
            'fk-actions',
            'Uses JOINED inheritance, for which ORM generates ON DELETE CASCADE from DDC2504ChildClass to its root table.',
        ],
        'Doctrine\Tests\ORM\Functional\ExtraLazyCollectionTest::testContainsKeyNonExistentIndexByOneToMany' => [
            'fk-actions',
            'Uses JOINED inheritance, for which ORM generates ON DELETE CASCADE from DDC2504ChildClass to its root table.',
        ],
        'Doctrine\Tests\ORM\Functional\ExtraLazyCollectionTest::testContainsManyToMany' => [
            'fk-actions',
            'Uses JOINED inheritance, for which ORM generates ON DELETE CASCADE from DDC2504ChildClass to its root table.',
        ],
        'Doctrine\Tests\ORM\Functional\ExtraLazyCollectionTest::testContainsManyToManyInverse' => [
            'fk-actions',
            'Uses JOINED inheritance, for which ORM generates ON DELETE CASCADE from DDC2504ChildClass to its root table.',
        ],
        'Doctrine\Tests\ORM\Functional\ExtraLazyCollectionTest::testContainsOnOneToManyJoinedInheritanceWillNotCauseQueriesWhenNonPersistentItemIsMatched' => [
            'fk-actions',
            'Uses JOINED inheritance, for which ORM generates ON DELETE CASCADE from DDC2504ChildClass to its root table.',
        ],
        'Doctrine\Tests\ORM\Functional\ExtraLazyCollectionTest::testContainsOnOneToManyJoinedInheritanceWillNotInitializeCollectionWhenMatchingItemIsFound' => [
            'fk-actions',
            'Uses JOINED inheritance, for which ORM generates ON DELETE CASCADE from DDC2504ChildClass to its root table.',
        ],
        'Doctrine\Tests\ORM\Functional\ExtraLazyCollectionTest::testContainsOnOneToManyJoinedInheritanceWillNotInitializeCollectionWithClearStateMatchingItem' => [
            'fk-actions',
            'Uses JOINED inheritance, for which ORM generates ON DELETE CASCADE from DDC2504ChildClass to its root table.',
        ],
        'Doctrine\Tests\ORM\Functional\ExtraLazyCollectionTest::testContainsOnOneToManyJoinedInheritanceWillNotInitializeCollectionWithNewStateNotMatchingItem' => [
            'fk-actions',
            'Uses JOINED inheritance, for which ORM generates ON DELETE CASCADE from DDC2504ChildClass to its root table.',
        ],
        'Doctrine\Tests\ORM\Functional\ExtraLazyCollectionTest::testContainsOneToMany' => [
            'fk-actions',
            'Uses JOINED inheritance, for which ORM generates ON DELETE CASCADE from DDC2504ChildClass to its root table.',
        ],
        'Doctrine\Tests\ORM\Functional\ExtraLazyCollectionTest::testCountAfterAddThenFlush' => [
            'fk-actions',
            'Uses JOINED inheritance, for which ORM generates ON DELETE CASCADE from DDC2504ChildClass to its root table.',
        ],
        'Doctrine\Tests\ORM\Functional\ExtraLazyCollectionTest::testCountInverseCollection' => [
            'fk-actions',
            'Uses JOINED inheritance, for which ORM generates ON DELETE CASCADE from DDC2504ChildClass to its root table.',
        ],
        'Doctrine\Tests\ORM\Functional\ExtraLazyCollectionTest::testCountNotInitializesCollection' => [
            'fk-actions',
            'Uses JOINED inheritance, for which ORM generates ON DELETE CASCADE from DDC2504ChildClass to its root table.',
        ],
        'Doctrine\Tests\ORM\Functional\ExtraLazyCollectionTest::testCountOneToMany' => [
            'fk-actions',
            'Uses JOINED inheritance, for which ORM generates ON DELETE CASCADE from DDC2504ChildClass to its root table.',
        ],
        'Doctrine\Tests\ORM\Functional\ExtraLazyCollectionTest::testCountOneToManyJoinedInheritance' => [
            'fk-actions',
            'Uses JOINED inheritance, for which ORM generates ON DELETE CASCADE from DDC2504ChildClass to its root table.',
        ],
        'Doctrine\Tests\ORM\Functional\ExtraLazyCollectionTest::testCountWhenInitialized' => [
            'fk-actions',
            'Uses JOINED inheritance, for which ORM generates ON DELETE CASCADE from DDC2504ChildClass to its root table.',
        ],
        'Doctrine\Tests\ORM\Functional\ExtraLazyCollectionTest::testCountWhenNewEntityPresent' => [
            'fk-actions',
            'Uses JOINED inheritance, for which ORM generates ON DELETE CASCADE from DDC2504ChildClass to its root table.',
        ],
        'Doctrine\Tests\ORM\Functional\ExtraLazyCollectionTest::testCountingOnOneToManyJoinedInheritanceWillNotInitializeCollection' => [
            'fk-actions',
            'Uses JOINED inheritance, for which ORM generates ON DELETE CASCADE from DDC2504ChildClass to its root table.',
        ],
        'Doctrine\Tests\ORM\Functional\ExtraLazyCollectionTest::testFirstOnEmptyCollectionWhenInitialized' => [
            'fk-actions',
            'Uses JOINED inheritance, for which ORM generates ON DELETE CASCADE from DDC2504ChildClass to its root table.',
        ],
        'Doctrine\Tests\ORM\Functional\ExtraLazyCollectionTest::testFirstOnEmptyCollectionWhenNotInitialized' => [
            'fk-actions',
            'Uses JOINED inheritance, for which ORM generates ON DELETE CASCADE from DDC2504ChildClass to its root table.',
        ],
        'Doctrine\Tests\ORM\Functional\ExtraLazyCollectionTest::testFirstWhenInitialized' => [
            'fk-actions',
            'Uses JOINED inheritance, for which ORM generates ON DELETE CASCADE from DDC2504ChildClass to its root table.',
        ],
        'Doctrine\Tests\ORM\Functional\ExtraLazyCollectionTest::testFirstWhenNotInitialized' => [
            'fk-actions',
            'Uses JOINED inheritance, for which ORM generates ON DELETE CASCADE from DDC2504ChildClass to its root table.',
        ],
        'Doctrine\Tests\ORM\Functional\ExtraLazyCollectionTest::testFullSlice' => [
            'fk-actions',
            'Uses JOINED inheritance, for which ORM generates ON DELETE CASCADE from DDC2504ChildClass to its root table.',
        ],
        'Doctrine\Tests\ORM\Functional\ExtraLazyCollectionTest::testGetIndexByIdentifier' => [
            'fk-actions',
            'Uses JOINED inheritance, for which ORM generates ON DELETE CASCADE from DDC2504ChildClass to its root table.',
        ],
        'Doctrine\Tests\ORM\Functional\ExtraLazyCollectionTest::testGetIndexByManyToManyInverseSide' => [
            'fk-actions',
            'Uses JOINED inheritance, for which ORM generates ON DELETE CASCADE from DDC2504ChildClass to its root table.',
        ],
        'Doctrine\Tests\ORM\Functional\ExtraLazyCollectionTest::testGetIndexByManyToManyOwningSide' => [
            'fk-actions',
            'Uses JOINED inheritance, for which ORM generates ON DELETE CASCADE from DDC2504ChildClass to its root table.',
        ],
        'Doctrine\Tests\ORM\Functional\ExtraLazyCollectionTest::testGetIndexByOneToMany' => [
            'fk-actions',
            'Uses JOINED inheritance, for which ORM generates ON DELETE CASCADE from DDC2504ChildClass to its root table.',
        ],
        'Doctrine\Tests\ORM\Functional\ExtraLazyCollectionTest::testGetNonExistentIndexBy' => [
            'fk-actions',
            'Uses JOINED inheritance, for which ORM generates ON DELETE CASCADE from DDC2504ChildClass to its root table.',
        ],
        'Doctrine\Tests\ORM\Functional\ExtraLazyCollectionTest::testLazyOneToManyJoinedInheritanceIsLazilyInitialized' => [
            'fk-actions',
            'Uses JOINED inheritance, for which ORM generates ON DELETE CASCADE from DDC2504ChildClass to its root table.',
        ],
        'Doctrine\Tests\ORM\Functional\ExtraLazyCollectionTest::testSlice' => [
            'fk-actions',
            'Uses JOINED inheritance, for which ORM generates ON DELETE CASCADE from DDC2504ChildClass to its root table.',
        ],
        'Doctrine\Tests\ORM\Functional\ExtraLazyCollectionTest::testSliceInitializedCollection' => [
            'fk-actions',
            'Uses JOINED inheritance, for which ORM generates ON DELETE CASCADE from DDC2504ChildClass to its root table.',
        ],
        'Doctrine\Tests\ORM\Functional\ExtraLazyCollectionTest::testSliceInverseCollection' => [
            'fk-actions',
            'Uses JOINED inheritance, for which ORM generates ON DELETE CASCADE from DDC2504ChildClass to its root table.',
        ],
        'Doctrine\Tests\ORM\Functional\ExtraLazyCollectionTest::testSliceOnDirtyCollection' => [
            'fk-actions',
            'Uses JOINED inheritance, for which ORM generates ON DELETE CASCADE from DDC2504ChildClass to its root table.',
        ],
        'Doctrine\Tests\ORM\Functional\ExtraLazyCollectionTest::testSliceOneToMany' => [
            'fk-actions',
            'Uses JOINED inheritance, for which ORM generates ON DELETE CASCADE from DDC2504ChildClass to its root table.',
        ],
        'Doctrine\Tests\ORM\Functional\GH11345Test::testUpdateSqlStatementsAreIssuedInAlphabeticOrderByEntityClass' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\GH11345Test::testUpdateSqlStatementsAreIssuedInAlphabeticOrderByEntityIdForSameClassName' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\GH8011Test::testOrderWithArithmeticExpressionWithLiteralAndResultVariable' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\GH8011Test::testOrderWithArithmeticExpressionWithLiteralAndResultVariable2' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\GH8011Test::testOrderWithArithmeticExpressionWithLiteralAndResultVariableUsingHiddenResultVariable' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\GH8011Test::testOrderWithArithmeticExpressionWithLiteralAndSingleValuedPathExpression' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\GH8011Test::testOrderWithArithmeticExpressionWithLiteralAndSingleValuedPathExpression2' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\GH8011Test::testOrderWithArithmeticExpressionWithResultVariableAndLiteral' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\GH8011Test::testOrderWithArithmeticExpressionWithResultVariableAndLiteral2' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\GH8011Test::testOrderWithArithmeticExpressionWithResultVariableAndSingleValuedPathExpression' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\GH8011Test::testOrderWithArithmeticExpressionWithResultVariableAndSingleValuedPathExpression2' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\GH8011Test::testOrderWithArithmeticExpressionWithSingleValuedPathExpression' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\GH8011Test::testOrderWithArithmeticExpressionWithSingleValuedPathExpressionAndLiteral' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\GH8011Test::testOrderWithArithmeticExpressionWithSingleValuedPathExpressionAndResultVariable' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\IndexByAssociationTest::testDqlOverrideIndexBy' => [
            'fk-actions',
            'Uses a many-to-many association whose default join columns ORM declares with ON DELETE CASCADE (exchange_bonds_stocks).',
        ],
        'Doctrine\Tests\ORM\Functional\IndexByAssociationTest::testManyToMany' => [
            'fk-actions',
            'Uses a many-to-many association whose default join columns ORM declares with ON DELETE CASCADE (exchange_bonds_stocks).',
        ],
        'Doctrine\Tests\ORM\Functional\IndexByAssociationTest::testManyToOneDQL' => [
            'fk-actions',
            'Uses a many-to-many association whose default join columns ORM declares with ON DELETE CASCADE (exchange_bonds_stocks).',
        ],
        'Doctrine\Tests\ORM\Functional\IndexByAssociationTest::testManyToOneFinder' => [
            'fk-actions',
            'Uses a many-to-many association whose default join columns ORM declares with ON DELETE CASCADE (exchange_bonds_stocks).',
        ],
        'Doctrine\Tests\ORM\Functional\IndexByAssociationTest::testManytoManyDQL' => [
            'fk-actions',
            'Uses a many-to-many association whose default join columns ORM declares with ON DELETE CASCADE (exchange_bonds_stocks).',
        ],
        'Doctrine\Tests\ORM\Functional\JoinedTableCompositeKeyTest::testInsertWithCompositeKey' => [
            'fk-actions',
            'Uses JOINED inheritance, for which ORM generates ON DELETE CASCADE from JoinedChildClass to its root table.',
        ],
        'Doctrine\Tests\ORM\Functional\JoinedTableCompositeKeyTest::testUpdateWithCompositeKey' => [
            'fk-actions',
            'Uses JOINED inheritance, for which ORM generates ON DELETE CASCADE from JoinedChildClass to its root table.',
        ],
        'Doctrine\Tests\ORM\Functional\Locking\OptimisticTest::testJoinedChildInsertSetsInitialVersionValue' => [
            'fk-actions',
            'Uses a many-to-many association whose default join columns ORM declares with ON DELETE CASCADE (optimistic_joined_child).',
        ],
        'Doctrine\Tests\ORM\Functional\Locking\OptimisticTest::testJoinedParentInsertSetsInitialVersionValue' => [
            'fk-actions',
            'Uses a many-to-many association whose default join columns ORM declares with ON DELETE CASCADE (optimistic_joined_child).',
        ],
        'Doctrine\Tests\ORM\Functional\Locking\OptimisticTest::testLockWorksWithProxy' => [
            'fk-actions',
            'Uses a many-to-many association whose default join columns ORM declares with ON DELETE CASCADE (optimistic_joined_child).',
        ],
        'Doctrine\Tests\ORM\Functional\Locking\OptimisticTest::testMultipleFlushesDoIncrementalUpdates' => [
            'fk-actions',
            'Uses a many-to-many association whose default join columns ORM declares with ON DELETE CASCADE (optimistic_joined_child).',
        ],
        'Doctrine\Tests\ORM\Functional\Locking\OptimisticTest::testOptimisticTimestampSetsDefaultValue' => [
            'fk-actions',
            'Uses a many-to-many association whose default join columns ORM declares with ON DELETE CASCADE (optimistic_joined_child).',
        ],
        'Doctrine\Tests\ORM\Functional\Locking\OptimisticTest::testStandardInsertSetsInitialVersionValue' => [
            'fk-actions',
            'Uses a many-to-many association whose default join columns ORM declares with ON DELETE CASCADE (optimistic_joined_child).',
        ],
        'Doctrine\Tests\ORM\Functional\NativeQueryTest::testAbstractClassInSingleTableInheritanceSchemaWithRSMBuilderThrowsException' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\NativeQueryTest::testBasicNativeQuery' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\NativeQueryTest::testBasicNativeQueryWithMetaResult' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\NativeQueryTest::testConcreteClassInSingleTableInheritanceSchemaWithRSMBuilderIsFine' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\NativeQueryTest::testFluentInterface' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\NativeQueryTest::testGenerateSelectClauseCustomRenames' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\NativeQueryTest::testGenerateSelectClauseIncrement' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\NativeQueryTest::testGenerateSelectClauseNoRenameSingleEntity' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\NativeQueryTest::testGenerateSelectClauseRenameTableAlias' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\NativeQueryTest::testGenerateSelectClauseToString' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\NativeQueryTest::testGenerateSelectClauseWithCustomTypeUsingAddFieldResult' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\NativeQueryTest::testGenerateSelectClauseWithCustomTypeUsingEntityFromClassMetadata' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\NativeQueryTest::testGenerateSelectClauseWithDiscriminatorColumn' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\NativeQueryTest::testJoinedOneToManyNativeQuery' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\NativeQueryTest::testJoinedOneToManyNativeQueryWithRSMBuilder' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\NativeQueryTest::testJoinedOneToOneNativeQuery' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\NativeQueryTest::testJoinedOneToOneNativeQueryWithRSMBuilder' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\NativeQueryTest::testMappingAsDto' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\NativeQueryTest::testNativeQueryWithArrayParameter' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\NativeQueryTest::testRSMBuilderThrowsExceptionOnColumnConflict' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\NativeQueryTest::testUnknownParentAliasThrowsException' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\OrderedJoinedTableInheritanceCollectionTest::testOrderdOneToManyCollection' => [
            'fk-actions',
            'Uses a many-to-many association whose default join columns ORM declares with ON DELETE CASCADE (OJTICCat).',
        ],
        'Doctrine\Tests\ORM\Functional\PaginationTest::testCloneQuery' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\PaginationTest::testCountComplexWithOutputWalker' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\PaginationTest::testCountComplexWithoutOutputWalker' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\PaginationTest::testCountQueryStripsParametersInSelect' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\PaginationTest::testCountSimpleWithoutJoin' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\PaginationTest::testCountWithComplexScalarOrderBy' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\PaginationTest::testCountWithCountSubqueryInWhereClauseWithOutputWalker' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\PaginationTest::testCountWithFetchJoin' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\PaginationTest::testCursorPaginatorCannotBuildCursorFromFieldOfJoinedCollection' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\PaginationTest::testCursorPaginatorOrderedByFieldOfJoinedEntity' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\PaginationTest::testCursorPaginatorOrderedByFieldOfJoinedEntityThatIsNotSelected' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\PaginationTest::testCursorPaginatorOrderedByFieldOfJoinedEntityWithNonEntityResults' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\PaginationTest::testCursorPaginatorOrderedByFieldOfNestedJoinedEntity' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\PaginationTest::testCursorPaginatorWithFetchJoinStripsParametersOnlyUsedInSelect' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\PaginationTest::testCustomIdTypeWithoutOutputWalker' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\PaginationTest::testDetectOutputWalker' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\PaginationTest::testDifferentResultLengthsDoNotRequireExtraQueryCacheEntries' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\PaginationTest::testIterateComplexWithOutputWalker' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\PaginationTest::testIterateSimpleWithOutputWalkerWithoutJoinWithComplexOrder' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\PaginationTest::testIterateSimpleWithOutputWalkerWithoutJoinWithComplexOrderAndLimit' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\PaginationTest::testIterateSimpleWithOutputWalkerWithoutJoinWithComplexOrderAndLimitAndOffset' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\PaginationTest::testIterateSimpleWithoutJoin' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\PaginationTest::testIterateSimpleWithoutJoinWithOrder' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\PaginationTest::testIterateSimpleWithoutJoinWithOrderAndLimit' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\PaginationTest::testIterateSimpleWithoutJoinWithOrderAndLimitAndOffset' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\PaginationTest::testIterateWithCountSubqueryInWhereClause' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\PaginationTest::testIterateWithFetchJoin' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\PaginationTest::testIterateWithFetchJoinOneToManyWithOrderByColumnFromBoth' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\PaginationTest::testIterateWithFetchJoinOneToManyWithOrderByColumnFromBothWithLimitWithOutputWalker' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\PaginationTest::testIterateWithFetchJoinOneToManyWithOrderByColumnFromBothWithLimitWithoutOutputWalker' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\PaginationTest::testIterateWithFetchJoinOneToManyWithOrderByColumnFromJoined' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\PaginationTest::testIterateWithFetchJoinOneToManyWithOrderByColumnFromJoinedWithLimitWithOutputWalker' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\PaginationTest::testIterateWithFetchJoinOneToManyWithOrderByColumnFromJoinedWithLimitWithoutOutputWalker' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\PaginationTest::testIterateWithFetchJoinOneToManyWithOrderByColumnFromRoot' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\PaginationTest::testIterateWithFetchJoinOneToManyWithOrderByColumnFromRootWithLimit' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\PaginationTest::testIterateWithFetchJoinOneToManyWithOrderByColumnFromRootWithLimitAndOffset' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\PaginationTest::testIterateWithFetchJoinWithOrder' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\PaginationTest::testIterateWithFetchJoinWithOrderAndLimit' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\PaginationTest::testIterateWithFetchJoinWithOrderAndLimitAndOffset' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\PaginationTest::testIterateWithFetchJoinWithOrderByColumnFromJoined' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\PaginationTest::testIterateWithFetchJoinWithOrderByColumnFromJoinedWithLimit' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\PaginationTest::testIterateWithFetchJoinWithOrderByColumnFromJoinedWithLimitAndOffset' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\PaginationTest::testIterateWithOutputWalkersWithFetchJoinWithComplexOrderByReferencingJoined' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\PaginationTest::testIterateWithOutputWalkersWithFetchJoinWithComplexOrderByReferencingJoinedWithLimit' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\PaginationTest::testIterateWithOutputWalkersWithFetchJoinWithComplexOrderByReferencingJoinedWithLimitAndOffset' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\PaginationTest::testIterateWithOutputWalkersWithFetchJoinWithComplexOrderByReferencingJoinedWithLimitAndOffsetWithInheritanceType' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\PaginationTest::testIterateWithOutputWalkersWithRegularJoinWithComplexOrderByReferencingJoined' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\PaginationTest::testIterateWithOutputWalkersWithRegularJoinWithComplexOrderByReferencingJoinedWithLimit' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\PaginationTest::testIterateWithOutputWalkersWithRegularJoinWithComplexOrderByReferencingJoinedWithLimitAndOffset' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\PaginationTest::testIterateWithRegularJoinWithOrderByColumnFromJoined' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\PaginationTest::testIterateWithRegularJoinWithOrderByColumnFromJoinedWithLimit' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\PaginationTest::testIterateWithRegularJoinWithOrderByColumnFromJoinedWithLimitAndOffset' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\PaginationTest::testJoinedClassTableInheritance' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\PaginationTest::testOffsetPaginatorIsReusableAcrossQueriesAndPages' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\PaginationTest::testOffsetPaginatorRejectsANonWindowPosition' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\PaginationTest::testOffsetPaginatorReturnsFirstPage' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\PaginationTest::testOffsetPaginatorReturnsLastPage' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\PaginationTest::testOffsetPaginatorWithFetchJoin' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\PaginationTest::testOffsetPaginatorWithFromPageNumberAndSize' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\PaginationTest::testPageItemsAreAListEvenForIndexByQueries' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\PaginationTest::testPaginationWithColumnAttributeNameDifference' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\PaginationTest::testPaginationWithSubSelectOrderByExpression' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\PaginationTest::testQueryWalkerIsKept' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\ParserResultSerializationTest::testSerializeParserResultForQueryWithSqlOutputWalker' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\ParserResultSerializationTest::testSerializeParserResultForQueryWithSqlWalker' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\ParserResultSerializationTest::testSymfony44ProvidedData' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\ParserResultSerializationTest::testUnserializeSingleSelectResult' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\QueryDqlFunctionTest::testAggregateAvg' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\QueryDqlFunctionTest::testAggregateCount' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\QueryDqlFunctionTest::testAggregateMax' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\QueryDqlFunctionTest::testAggregateMin' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\QueryDqlFunctionTest::testAggregateSum' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\QueryDqlFunctionTest::testBitAndComparison' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\QueryDqlFunctionTest::testBitOrComparison' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\QueryDqlFunctionTest::testConcatFunction' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\QueryDqlFunctionTest::testDateAdd' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\QueryDqlFunctionTest::testDateAddWithColumnInterval' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\QueryDqlFunctionTest::testDateDiff' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\QueryDqlFunctionTest::testDateSub' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\QueryDqlFunctionTest::testDateSubWithColumnInterval' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\QueryDqlFunctionTest::testFunctionAbs' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\QueryDqlFunctionTest::testFunctionConcat' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\QueryDqlFunctionTest::testFunctionLength' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\QueryDqlFunctionTest::testFunctionLocate' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\QueryDqlFunctionTest::testFunctionLower' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\QueryDqlFunctionTest::testFunctionMod' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\QueryDqlFunctionTest::testFunctionSqrt' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\QueryDqlFunctionTest::testFunctionSubstring' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\QueryDqlFunctionTest::testFunctionTrim' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\QueryDqlFunctionTest::testFunctionUpper' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\QueryDqlFunctionTest::testInArithmeticExpression1' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\QueryDqlFunctionTest::testInArithmeticExpression2' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\QueryDqlFunctionTest::testOperatorAdd' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\QueryDqlFunctionTest::testOperatorDiv' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\QueryDqlFunctionTest::testOperatorMultiply' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\QueryDqlFunctionTest::testOperatorSub' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\ReadonlyPropertiesTest::testEntityWithEagerManyToOne' => [
            'fk-actions',
            'Uses a many-to-many association whose default join columns ORM declares with ON DELETE CASCADE (book_author).',
        ],
        'Doctrine\Tests\ORM\Functional\ReadonlyPropertiesTest::testEntityWithLazyManyToOne' => [
            'fk-actions',
            'Uses a many-to-many association whose default join columns ORM declares with ON DELETE CASCADE (book_author).',
        ],
        'Doctrine\Tests\ORM\Functional\ReadonlyPropertiesTest::testEntityWithManyToMany' => [
            'fk-actions',
            'Uses a many-to-many association whose default join columns ORM declares with ON DELETE CASCADE (book_author).',
        ],
        'Doctrine\Tests\ORM\Functional\ReadonlyPropertiesTest::testSimpleEntity' => [
            'fk-actions',
            'Uses a many-to-many association whose default join columns ORM declares with ON DELETE CASCADE (book_author).',
        ],
        'Doctrine\Tests\ORM\Functional\ReferenceProxyTest::testAccessMetatadaForProxy' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\ReferenceProxyTest::testCloneProxy' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\ReferenceProxyTest::testCloneProxyWithResetId' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\ReferenceProxyTest::testCommonPersistenceProxy' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\ReferenceProxyTest::testDoNotInitializeProxyOnGettingTheIdentifier' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\ReferenceProxyTest::testDoNotInitializeProxyOnGettingTheIdentifierAndReturnTheRightType' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\ReferenceProxyTest::testDoNotInitializeProxyOnGettingTheIdentifierDDC1625' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\ReferenceProxyTest::testInitializeChangeAndFlushProxy' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\ReferenceProxyTest::testInitializeProxy' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\ReferenceProxyTest::testInitializeProxyOnGettingSomethingOtherThanTheIdentifier' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\ReferenceProxyTest::testLazyLoadsFieldValuesFromDatabase' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\ReferenceProxyTest::testReferenceFind' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\ReferenceProxyTest::testWakeupOnProxy' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\SQLFilterTest::testConfigureFilter' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\SQLFilterTest::testEntityManagerDisableFilter' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\SQLFilterTest::testEntityManagerEnableFilter' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\SQLFilterTest::testEntityManagerEnabledFilters' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\SQLFilterTest::testEntityManagerGetFilter' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\SQLFilterTest::testEntityManagerIsFilterEnabled' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\SQLFilterTest::testJoinSubclassPersisterFilterOnlyOnRootTableWhenFetchingRootEntity' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\SQLFilterTest::testJoinSubclassPersisterFilterOnlyOnRootTableWhenFetchingSubEntity' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\SQLFilterTest::testManyToManyExtraLazyContainsWithFilter' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\SQLFilterTest::testManyToManyExtraLazyContainsWithFilterOnCTI' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\SQLFilterTest::testManyToManyExtraLazyContainsWithFilterOnSTI' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\SQLFilterTest::testManyToManyExtraLazyCountWithFilter' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\SQLFilterTest::testManyToManyExtraLazyCountWithFilterOnCTI' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\SQLFilterTest::testManyToManyExtraLazyCountWithFilterOnSTI' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\SQLFilterTest::testManyToManyExtraLazySliceWithFilter' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\SQLFilterTest::testManyToManyExtraLazySliceWithFilterOnCTI' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\SQLFilterTest::testManyToManyExtraLazySliceWithFilterOnSTI' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\SQLFilterTest::testManyToManyFilter' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\SQLFilterTest::testOneToManyExtraLazyContainsWithFilter' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\SQLFilterTest::testOneToManyExtraLazyContainsWithFilterOnCTI' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\SQLFilterTest::testOneToManyExtraLazyContainsWithFilterOnSTI' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\SQLFilterTest::testOneToManyExtraLazyCountWithFilter' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\SQLFilterTest::testOneToManyExtraLazyCountWithFilterOnCTI' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\SQLFilterTest::testOneToManyExtraLazyCountWithFilterOnSTI' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\SQLFilterTest::testOneToManyExtraLazySliceWithFilter' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\SQLFilterTest::testOneToManyExtraLazySliceWithFilterOnCTI' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\SQLFilterTest::testOneToManyExtraLazySliceWithFilterOnSTI' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\SQLFilterTest::testOneToOneInverseSideWithFilter' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\SQLFilterTest::testQueryCacheDependsOnFilters' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\SQLFilterTest::testQueryGenerationDependsOnFilters' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\SQLFilterTest::testRepositoryFind' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\SQLFilterTest::testRepositoryFindAll' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\SQLFilterTest::testRepositoryFindBy' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\SQLFilterTest::testRepositoryFindByX' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\SQLFilterTest::testRepositoryFindOneBy' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\SQLFilterTest::testRepositoryFindOneByX' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\SQLFilterTest::testRetrieveListAsSingleThrowsException' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\SQLFilterTest::testRetrieveSingleAsListThrowsException' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\SQLFilterTest::testSQLFilterAddConstraint' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\SQLFilterTest::testSQLFilterGetConnection' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\SQLFilterTest::testSQLFilterGetSetParameter' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\SQLFilterTest::testSQLFilterSetArrayParameterInfersType' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\SQLFilterTest::testSQLFilterSetParameterInfersType' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\SQLFilterTest::testSQLFilterToString' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\SQLFilterTest::testSingleTableInheritanceFilterOnlyOnRootTableWhenFetchingRootEntity' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\SQLFilterTest::testSingleTableInheritanceFilterOnlyOnRootTableWhenFetchingSubEntity' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\SQLFilterTest::testToOneFilter' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\SQLFilterTest::testWhereFilter' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\SQLFilterTest::testWhereOrFilter' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\SchemaTool\CompanySchemaTest::testGeneratedSchema' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\SchemaTool\DDC214Test::testCompanyModel' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\SecondLevelCacheCompositePrimaryKeyTest::testPutAndLoadCompositPrimaryKeyEntities' => [
            'fk-actions',
            'Uses the cache models, whose JOINED inheritance makes ORM generate ON DELETE CASCADE from cache_attraction_contact_info to its root table.',
        ],
        'Doctrine\Tests\ORM\Functional\SecondLevelCacheCompositePrimaryKeyTest::testRemoveCompositPrimaryKeyEntities' => [
            'fk-actions',
            'Uses the cache models, whose JOINED inheritance makes ORM generate ON DELETE CASCADE from cache_attraction_contact_info to its root table.',
        ],
        'Doctrine\Tests\ORM\Functional\SecondLevelCacheCompositePrimaryKeyTest::testUpdateCompositPrimaryKeyEntities' => [
            'fk-actions',
            'Uses the cache models, whose JOINED inheritance makes ORM generate ON DELETE CASCADE from cache_attraction_contact_info to its root table.',
        ],
        'Doctrine\Tests\ORM\Functional\SecondLevelCacheConcurrentTest::testBasicConcurrentCollectionReadLock' => [
            'fk-actions',
            'Uses the cache models, whose JOINED inheritance makes ORM generate ON DELETE CASCADE from cache_attraction_contact_info to its root table.',
        ],
        'Doctrine\Tests\ORM\Functional\SecondLevelCacheConcurrentTest::testBasicConcurrentEntityReadLock' => [
            'fk-actions',
            'Uses the cache models, whose JOINED inheritance makes ORM generate ON DELETE CASCADE from cache_attraction_contact_info to its root table.',
        ],
        'Doctrine\Tests\ORM\Functional\SecondLevelCacheCountQueriesTest::testDelete' => [
            'fk-actions',
            'Uses the cache models, whose JOINED inheritance makes ORM generate ON DELETE CASCADE from cache_attraction_contact_info to its root table.',
        ],
        'Doctrine\Tests\ORM\Functional\SecondLevelCacheCountQueriesTest::testInsertWithPostInsertIdentifier' => [
            'fk-actions',
            'Uses the cache models, whose JOINED inheritance makes ORM generate ON DELETE CASCADE from cache_attraction_contact_info to its root table.',
        ],
        'Doctrine\Tests\ORM\Functional\SecondLevelCacheCountQueriesTest::testInsertWithoutPostInsertIdentifier' => [
            'fk-actions',
            'Uses the cache models, whose JOINED inheritance makes ORM generate ON DELETE CASCADE from cache_attraction_contact_info to its root table.',
        ],
        'Doctrine\Tests\ORM\Functional\SecondLevelCacheCountQueriesTest::testUpdate' => [
            'fk-actions',
            'Uses the cache models, whose JOINED inheritance makes ORM generate ON DELETE CASCADE from cache_attraction_contact_info to its root table.',
        ],
        'Doctrine\Tests\ORM\Functional\SecondLevelCacheCriteriaTest::testCollectionMatching' => [
            'fk-actions',
            'Uses the cache models, whose JOINED inheritance makes ORM generate ON DELETE CASCADE from cache_attraction_contact_info to its root table.',
        ],
        'Doctrine\Tests\ORM\Functional\SecondLevelCacheCriteriaTest::testMatchingPut' => [
            'fk-actions',
            'Uses the cache models, whose JOINED inheritance makes ORM generate ON DELETE CASCADE from cache_attraction_contact_info to its root table.',
        ],
        'Doctrine\Tests\ORM\Functional\SecondLevelCacheCriteriaTest::testRepositoryMatching' => [
            'fk-actions',
            'Uses the cache models, whose JOINED inheritance makes ORM generate ON DELETE CASCADE from cache_attraction_contact_info to its root table.',
        ],
        'Doctrine\Tests\ORM\Functional\SecondLevelCacheExtraLazyCollectionTest::testCacheCountAfterAddThenFlush' => [
            'fk-actions',
            'Uses the cache models, whose JOINED inheritance makes ORM generate ON DELETE CASCADE from cache_attraction_contact_info to its root table.',
        ],
        'Doctrine\Tests\ORM\Functional\SecondLevelCacheJoinTableInheritanceTest::testJoinTableCountaisRootClass' => [
            'fk-actions',
            'Uses the cache models, whose JOINED inheritance makes ORM generate ON DELETE CASCADE from cache_attraction_contact_info to its root table.',
        ],
        'Doctrine\Tests\ORM\Functional\SecondLevelCacheJoinTableInheritanceTest::testOneToManyRelationJoinTable' => [
            'fk-actions',
            'Uses the cache models, whose JOINED inheritance makes ORM generate ON DELETE CASCADE from cache_attraction_contact_info to its root table.',
        ],
        'Doctrine\Tests\ORM\Functional\SecondLevelCacheJoinTableInheritanceTest::testPutAndLoadJoinTableEntities' => [
            'fk-actions',
            'Uses the cache models, whose JOINED inheritance makes ORM generate ON DELETE CASCADE from cache_attraction_contact_info to its root table.',
        ],
        'Doctrine\Tests\ORM\Functional\SecondLevelCacheJoinTableInheritanceTest::testPutOnPersistJoinTableInheritance' => [
            'fk-actions',
            'Uses the cache models, whose JOINED inheritance makes ORM generate ON DELETE CASCADE from cache_attraction_contact_info to its root table.',
        ],
        'Doctrine\Tests\ORM\Functional\SecondLevelCacheJoinTableInheritanceTest::testQueryCacheFindAllJoinTableEntities' => [
            'fk-actions',
            'Uses the cache models, whose JOINED inheritance makes ORM generate ON DELETE CASCADE from cache_attraction_contact_info to its root table.',
        ],
        'Doctrine\Tests\ORM\Functional\SecondLevelCacheJoinTableInheritanceTest::testQueryCacheShouldBeEvictedOnTimestampUpdate' => [
            'fk-actions',
            'Uses the cache models, whose JOINED inheritance makes ORM generate ON DELETE CASCADE from cache_attraction_contact_info to its root table.',
        ],
        'Doctrine\Tests\ORM\Functional\SecondLevelCacheJoinTableInheritanceTest::testUseSameRegion' => [
            'fk-actions',
            'Uses the cache models, whose JOINED inheritance makes ORM generate ON DELETE CASCADE from cache_attraction_contact_info to its root table.',
        ],
        'Doctrine\Tests\ORM\Functional\SecondLevelCacheManyToManyTest::testManyToManyWithEmptyRelation' => [
            'fk-actions',
            'Uses the cache models, whose JOINED inheritance makes ORM generate ON DELETE CASCADE from cache_attraction_contact_info to its root table.',
        ],
        'Doctrine\Tests\ORM\Functional\SecondLevelCacheManyToManyTest::testPutAndLoadManyToManyRelation' => [
            'fk-actions',
            'Uses the cache models, whose JOINED inheritance makes ORM generate ON DELETE CASCADE from cache_attraction_contact_info to its root table.',
        ],
        'Doctrine\Tests\ORM\Functional\SecondLevelCacheManyToManyTest::testReadOnlyCollection' => [
            'fk-actions',
            'Uses the cache models, whose JOINED inheritance makes ORM generate ON DELETE CASCADE from cache_attraction_contact_info to its root table.',
        ],
        'Doctrine\Tests\ORM\Functional\SecondLevelCacheManyToManyTest::testShouldPutManyToManyCollectionOwningSideOnPersist' => [
            'fk-actions',
            'Uses the cache models, whose JOINED inheritance makes ORM generate ON DELETE CASCADE from cache_attraction_contact_info to its root table.',
        ],
        'Doctrine\Tests\ORM\Functional\SecondLevelCacheManyToManyTest::testStoreManyToManyAssociationWhitCascade' => [
            'fk-actions',
            'Uses the cache models, whose JOINED inheritance makes ORM generate ON DELETE CASCADE from cache_attraction_contact_info to its root table.',
        ],
        'Doctrine\Tests\ORM\Functional\SecondLevelCacheManyToOneTest::testInverseSidePutShouldEvictCollection' => [
            'fk-actions',
            'Uses the cache models, whose JOINED inheritance makes ORM generate ON DELETE CASCADE from cache_attraction_contact_info to its root table.',
        ],
        'Doctrine\Tests\ORM\Functional\SecondLevelCacheManyToOneTest::testPutAndLoadManyToOneRelation' => [
            'fk-actions',
            'Uses the cache models, whose JOINED inheritance makes ORM generate ON DELETE CASCADE from cache_attraction_contact_info to its root table.',
        ],
        'Doctrine\Tests\ORM\Functional\SecondLevelCacheManyToOneTest::testPutAndLoadNonCacheableCompositeManyToOne' => [
            'fk-actions',
            'Uses the cache models, whose JOINED inheritance makes ORM generate ON DELETE CASCADE from cache_attraction_contact_info to its root table.',
        ],
        'Doctrine\Tests\ORM\Functional\SecondLevelCacheManyToOneTest::testPutAndLoadNonCacheableManyToOne' => [
            'fk-actions',
            'Uses the cache models, whose JOINED inheritance makes ORM generate ON DELETE CASCADE from cache_attraction_contact_info to its root table.',
        ],
        'Doctrine\Tests\ORM\Functional\SecondLevelCacheManyToOneTest::testPutOnPersist' => [
            'fk-actions',
            'Uses the cache models, whose JOINED inheritance makes ORM generate ON DELETE CASCADE from cache_attraction_contact_info to its root table.',
        ],
        'Doctrine\Tests\ORM\Functional\SecondLevelCacheManyToOneTest::testShouldNotReloadWhenAssociationIsMissing' => [
            'fk-actions',
            'Uses the cache models, whose JOINED inheritance makes ORM generate ON DELETE CASCADE from cache_attraction_contact_info to its root table.',
        ],
        'Doctrine\Tests\ORM\Functional\SecondLevelCacheOneToManyTest::testCacheInitializeCollectionWithNewObjects' => [
            'fk-actions',
            'Uses the cache models, whose JOINED inheritance makes ORM generate ON DELETE CASCADE from cache_attraction_contact_info to its root table.',
        ],
        'Doctrine\Tests\ORM\Functional\SecondLevelCacheOneToManyTest::testLoadOneToManyCollectionFromDatabaseWhenEntityMissing' => [
            'fk-actions',
            'Uses the cache models, whose JOINED inheritance makes ORM generate ON DELETE CASCADE from cache_attraction_contact_info to its root table.',
        ],
        'Doctrine\Tests\ORM\Functional\SecondLevelCacheOneToManyTest::testOneToManyCount' => [
            'fk-actions',
            'Uses the cache models, whose JOINED inheritance makes ORM generate ON DELETE CASCADE from cache_attraction_contact_info to its root table.',
        ],
        'Doctrine\Tests\ORM\Functional\SecondLevelCacheOneToManyTest::testOneToManyRemove' => [
            'fk-actions',
            'Uses the cache models, whose JOINED inheritance makes ORM generate ON DELETE CASCADE from cache_attraction_contact_info to its root table.',
        ],
        'Doctrine\Tests\ORM\Functional\SecondLevelCacheOneToManyTest::testOneToManyWithEmptyRelation' => [
            'fk-actions',
            'Uses the cache models, whose JOINED inheritance makes ORM generate ON DELETE CASCADE from cache_attraction_contact_info to its root table.',
        ],
        'Doctrine\Tests\ORM\Functional\SecondLevelCacheOneToManyTest::testPutAndLoadNonCacheableOneToMany' => [
            'fk-actions',
            'Uses the cache models, whose JOINED inheritance makes ORM generate ON DELETE CASCADE from cache_attraction_contact_info to its root table.',
        ],
        'Doctrine\Tests\ORM\Functional\SecondLevelCacheOneToManyTest::testPutAndLoadOneToManyRelation' => [
            'fk-actions',
            'Uses the cache models, whose JOINED inheritance makes ORM generate ON DELETE CASCADE from cache_attraction_contact_info to its root table.',
        ],
        'Doctrine\Tests\ORM\Functional\SecondLevelCacheOneToManyTest::testShoudNotPutOneToManyRelationOnPersist' => [
            'fk-actions',
            'Uses the cache models, whose JOINED inheritance makes ORM generate ON DELETE CASCADE from cache_attraction_contact_info to its root table.',
        ],
        'Doctrine\Tests\ORM\Functional\SecondLevelCacheOneToManyTest::testShouldPutCollectionInverseSideOnPersist' => [
            'fk-actions',
            'Uses the cache models, whose JOINED inheritance makes ORM generate ON DELETE CASCADE from cache_attraction_contact_info to its root table.',
        ],
        'Doctrine\Tests\ORM\Functional\SecondLevelCacheOneToOneTest::testInverseSidePutAndLoadOneToOneBidirectionalRelation' => [
            'fk-actions',
            'Uses the cache models, whose JOINED inheritance makes ORM generate ON DELETE CASCADE from cache_attraction_contact_info to its root table.',
        ],
        'Doctrine\Tests\ORM\Functional\SecondLevelCacheOneToOneTest::testPutAndLoadNonCacheableOneToOne' => [
            'fk-actions',
            'Uses the cache models, whose JOINED inheritance makes ORM generate ON DELETE CASCADE from cache_attraction_contact_info to its root table.',
        ],
        'Doctrine\Tests\ORM\Functional\SecondLevelCacheOneToOneTest::testPutAndLoadOneToOneBidirectionalRelation' => [
            'fk-actions',
            'Uses the cache models, whose JOINED inheritance makes ORM generate ON DELETE CASCADE from cache_attraction_contact_info to its root table.',
        ],
        'Doctrine\Tests\ORM\Functional\SecondLevelCacheOneToOneTest::testPutAndLoadOneToOneUnidirectionalRelation' => [
            'fk-actions',
            'Uses the cache models, whose JOINED inheritance makes ORM generate ON DELETE CASCADE from cache_attraction_contact_info to its root table.',
        ],
        'Doctrine\Tests\ORM\Functional\SecondLevelCacheOneToOneTest::testPutOneToOneOnBidirectionalPersist' => [
            'fk-actions',
            'Uses the cache models, whose JOINED inheritance makes ORM generate ON DELETE CASCADE from cache_attraction_contact_info to its root table.',
        ],
        'Doctrine\Tests\ORM\Functional\SecondLevelCacheOneToOneTest::testPutOneToOneOnUnidirectionalPersist' => [
            'fk-actions',
            'Uses the cache models, whose JOINED inheritance makes ORM generate ON DELETE CASCADE from cache_attraction_contact_info to its root table.',
        ],
        'Doctrine\Tests\ORM\Functional\SecondLevelCacheQueryCacheTest::testBasicNativeQueryCache' => [
            'fk-actions',
            'Uses the cache models, whose JOINED inheritance makes ORM generate ON DELETE CASCADE from cache_attraction_contact_info to its root table.',
        ],
        'Doctrine\Tests\ORM\Functional\SecondLevelCacheQueryCacheTest::testBasicQueryCache' => [
            'fk-actions',
            'Uses the cache models, whose JOINED inheritance makes ORM generate ON DELETE CASCADE from cache_attraction_contact_info to its root table.',
        ],
        'Doctrine\Tests\ORM\Functional\SecondLevelCacheQueryCacheTest::testBasicQueryCachePutEntityCache' => [
            'fk-actions',
            'Uses the cache models, whose JOINED inheritance makes ORM generate ON DELETE CASCADE from cache_attraction_contact_info to its root table.',
        ],
        'Doctrine\Tests\ORM\Functional\SecondLevelCacheQueryCacheTest::testBasicQueryFetchJoinsManyToOne' => [
            'fk-actions',
            'Uses the cache models, whose JOINED inheritance makes ORM generate ON DELETE CASCADE from cache_attraction_contact_info to its root table.',
        ],
        'Doctrine\Tests\ORM\Functional\SecondLevelCacheQueryCacheTest::testBasicQueryFetchJoinsOneToMany' => [
            'fk-actions',
            'Uses the cache models, whose JOINED inheritance makes ORM generate ON DELETE CASCADE from cache_attraction_contact_info to its root table.',
        ],
        'Doctrine\Tests\ORM\Functional\SecondLevelCacheQueryCacheTest::testBasicQueryParams' => [
            'fk-actions',
            'Uses the cache models, whose JOINED inheritance makes ORM generate ON DELETE CASCADE from cache_attraction_contact_info to its root table.',
        ],
        'Doctrine\Tests\ORM\Functional\SecondLevelCacheQueryCacheTest::testCacheableForcePartialLoadHintQueryException' => [
            'fk-actions',
            'Uses the cache models, whose JOINED inheritance makes ORM generate ON DELETE CASCADE from cache_attraction_contact_info to its root table.',
        ],
        'Doctrine\Tests\ORM\Functional\SecondLevelCacheQueryCacheTest::testCacheablePartialQueryException' => [
            'fk-actions',
            'Uses the cache models, whose JOINED inheritance makes ORM generate ON DELETE CASCADE from cache_attraction_contact_info to its root table.',
        ],
        'Doctrine\Tests\ORM\Functional\SecondLevelCacheQueryCacheTest::testHintClearEntityRegionDeleteStatement' => [
            'fk-actions',
            'Uses the cache models, whose JOINED inheritance makes ORM generate ON DELETE CASCADE from cache_attraction_contact_info to its root table.',
        ],
        'Doctrine\Tests\ORM\Functional\SecondLevelCacheQueryCacheTest::testHintClearEntityRegionUpdateStatement' => [
            'fk-actions',
            'Uses the cache models, whose JOINED inheritance makes ORM generate ON DELETE CASCADE from cache_attraction_contact_info to its root table.',
        ],
        'Doctrine\Tests\ORM\Functional\SecondLevelCacheQueryCacheTest::testLoadFromDatabaseWhenEntityMissing' => [
            'fk-actions',
            'Uses the cache models, whose JOINED inheritance makes ORM generate ON DELETE CASCADE from cache_attraction_contact_info to its root table.',
        ],
        'Doctrine\Tests\ORM\Functional\SecondLevelCacheQueryCacheTest::testMultipleNestedDQLAliases' => [
            'fk-actions',
            'Uses the cache models, whose JOINED inheritance makes ORM generate ON DELETE CASCADE from cache_attraction_contact_info to its root table.',
        ],
        'Doctrine\Tests\ORM\Functional\SecondLevelCacheQueryCacheTest::testNonCacheableQueryDeleteStatementException' => [
            'fk-actions',
            'Uses the cache models, whose JOINED inheritance makes ORM generate ON DELETE CASCADE from cache_attraction_contact_info to its root table.',
        ],
        'Doctrine\Tests\ORM\Functional\SecondLevelCacheQueryCacheTest::testNonCacheableQueryUpdateStatementException' => [
            'fk-actions',
            'Uses the cache models, whose JOINED inheritance makes ORM generate ON DELETE CASCADE from cache_attraction_contact_info to its root table.',
        ],
        'Doctrine\Tests\ORM\Functional\SecondLevelCacheQueryCacheTest::testQueryCacheLifetime' => [
            'fk-actions',
            'Uses the cache models, whose JOINED inheritance makes ORM generate ON DELETE CASCADE from cache_attraction_contact_info to its root table.',
        ],
        'Doctrine\Tests\ORM\Functional\SecondLevelCacheQueryCacheTest::testQueryCacheModeGet' => [
            'fk-actions',
            'Uses the cache models, whose JOINED inheritance makes ORM generate ON DELETE CASCADE from cache_attraction_contact_info to its root table.',
        ],
        'Doctrine\Tests\ORM\Functional\SecondLevelCacheQueryCacheTest::testQueryCacheModePut' => [
            'fk-actions',
            'Uses the cache models, whose JOINED inheritance makes ORM generate ON DELETE CASCADE from cache_attraction_contact_info to its root table.',
        ],
        'Doctrine\Tests\ORM\Functional\SecondLevelCacheQueryCacheTest::testQueryCacheModeRefresh' => [
            'fk-actions',
            'Uses the cache models, whose JOINED inheritance makes ORM generate ON DELETE CASCADE from cache_attraction_contact_info to its root table.',
        ],
        'Doctrine\Tests\ORM\Functional\SecondLevelCacheQueryCacheTest::testQueryCacheRegion' => [
            'fk-actions',
            'Uses the cache models, whose JOINED inheritance makes ORM generate ON DELETE CASCADE from cache_attraction_contact_info to its root table.',
        ],
        'Doctrine\Tests\ORM\Functional\SecondLevelCacheQueryCacheTest::testQueryCacheShouldBeEvictedOnTimestampUpdate' => [
            'fk-actions',
            'Uses the cache models, whose JOINED inheritance makes ORM generate ON DELETE CASCADE from cache_attraction_contact_info to its root table.',
        ],
        'Doctrine\Tests\ORM\Functional\SecondLevelCacheQueryCacheTest::testQueryDependsOnFirstAndMaxResultResult' => [
            'fk-actions',
            'Uses the cache models, whose JOINED inheritance makes ORM generate ON DELETE CASCADE from cache_attraction_contact_info to its root table.',
        ],
        'Doctrine\Tests\ORM\Functional\SecondLevelCacheQueryCacheTest::testReloadQueryIfToManyAssociationItemIsNotFound' => [
            'fk-actions',
            'Uses the cache models, whose JOINED inheritance makes ORM generate ON DELETE CASCADE from cache_attraction_contact_info to its root table.',
        ],
        'Doctrine\Tests\ORM\Functional\SecondLevelCacheQueryCacheTest::testReloadQueryIfToOneIsNotFound' => [
            'fk-actions',
            'Uses the cache models, whose JOINED inheritance makes ORM generate ON DELETE CASCADE from cache_attraction_contact_info to its root table.',
        ],
        'Doctrine\Tests\ORM\Functional\SecondLevelCacheQueryCacheTest::testResolveAssociationCacheEntry' => [
            'fk-actions',
            'Uses the cache models, whose JOINED inheritance makes ORM generate ON DELETE CASCADE from cache_attraction_contact_info to its root table.',
        ],
        'Doctrine\Tests\ORM\Functional\SecondLevelCacheQueryCacheTest::testResolveToManyAssociationCacheEntry' => [
            'fk-actions',
            'Uses the cache models, whose JOINED inheritance makes ORM generate ON DELETE CASCADE from cache_attraction_contact_info to its root table.',
        ],
        'Doctrine\Tests\ORM\Functional\SecondLevelCacheQueryCacheTest::testResolveToOneAssociationCacheEntry' => [
            'fk-actions',
            'Uses the cache models, whose JOINED inheritance makes ORM generate ON DELETE CASCADE from cache_attraction_contact_info to its root table.',
        ],
        'Doctrine\Tests\ORM\Functional\SecondLevelCacheRepositoryTest::testRepositoryCacheFind' => [
            'fk-actions',
            'Uses the cache models, whose JOINED inheritance makes ORM generate ON DELETE CASCADE from cache_attraction_contact_info to its root table.',
        ],
        'Doctrine\Tests\ORM\Functional\SecondLevelCacheRepositoryTest::testRepositoryCacheFindAll' => [
            'fk-actions',
            'Uses the cache models, whose JOINED inheritance makes ORM generate ON DELETE CASCADE from cache_attraction_contact_info to its root table.',
        ],
        'Doctrine\Tests\ORM\Functional\SecondLevelCacheRepositoryTest::testRepositoryCacheFindAllInvalidation' => [
            'fk-actions',
            'Uses the cache models, whose JOINED inheritance makes ORM generate ON DELETE CASCADE from cache_attraction_contact_info to its root table.',
        ],
        'Doctrine\Tests\ORM\Functional\SecondLevelCacheRepositoryTest::testRepositoryCacheFindAllToOneAssociation' => [
            'fk-actions',
            'Uses the cache models, whose JOINED inheritance makes ORM generate ON DELETE CASCADE from cache_attraction_contact_info to its root table.',
        ],
        'Doctrine\Tests\ORM\Functional\SecondLevelCacheRepositoryTest::testRepositoryCacheFindBy' => [
            'fk-actions',
            'Uses the cache models, whose JOINED inheritance makes ORM generate ON DELETE CASCADE from cache_attraction_contact_info to its root table.',
        ],
        'Doctrine\Tests\ORM\Functional\SecondLevelCacheRepositoryTest::testRepositoryCacheFindOneBy' => [
            'fk-actions',
            'Uses the cache models, whose JOINED inheritance makes ORM generate ON DELETE CASCADE from cache_attraction_contact_info to its root table.',
        ],
        'Doctrine\Tests\ORM\Functional\SecondLevelCacheSingleTableInheritanceTest::testCountaisRootClass' => [
            'fk-actions',
            'Uses the cache models, whose JOINED inheritance makes ORM generate ON DELETE CASCADE from cache_attraction_contact_info to its root table.',
        ],
        'Doctrine\Tests\ORM\Functional\SecondLevelCacheSingleTableInheritanceTest::testOneToManyRelationSingleTable' => [
            'fk-actions',
            'Uses the cache models, whose JOINED inheritance makes ORM generate ON DELETE CASCADE from cache_attraction_contact_info to its root table.',
        ],
        'Doctrine\Tests\ORM\Functional\SecondLevelCacheSingleTableInheritanceTest::testPutAndLoadEntities' => [
            'fk-actions',
            'Uses the cache models, whose JOINED inheritance makes ORM generate ON DELETE CASCADE from cache_attraction_contact_info to its root table.',
        ],
        'Doctrine\Tests\ORM\Functional\SecondLevelCacheSingleTableInheritanceTest::testPutOnPersistSingleTableInheritance' => [
            'fk-actions',
            'Uses the cache models, whose JOINED inheritance makes ORM generate ON DELETE CASCADE from cache_attraction_contact_info to its root table.',
        ],
        'Doctrine\Tests\ORM\Functional\SecondLevelCacheSingleTableInheritanceTest::testQueryCacheFindAll' => [
            'fk-actions',
            'Uses the cache models, whose JOINED inheritance makes ORM generate ON DELETE CASCADE from cache_attraction_contact_info to its root table.',
        ],
        'Doctrine\Tests\ORM\Functional\SecondLevelCacheSingleTableInheritanceTest::testQueryCacheShouldBeEvictedOnTimestampUpdate' => [
            'fk-actions',
            'Uses the cache models, whose JOINED inheritance makes ORM generate ON DELETE CASCADE from cache_attraction_contact_info to its root table.',
        ],
        'Doctrine\Tests\ORM\Functional\SecondLevelCacheSingleTableInheritanceTest::testShouldNotPutOneToManyRelationOnPersist' => [
            'fk-actions',
            'Uses the cache models, whose JOINED inheritance makes ORM generate ON DELETE CASCADE from cache_attraction_contact_info to its root table.',
        ],
        'Doctrine\Tests\ORM\Functional\SecondLevelCacheSingleTableInheritanceTest::testUseSameRegion' => [
            'fk-actions',
            'Uses the cache models, whose JOINED inheritance makes ORM generate ON DELETE CASCADE from cache_attraction_contact_info to its root table.',
        ],
        'Doctrine\Tests\ORM\Functional\SecondLevelCacheTest::testCachedNewEntityExists' => [
            'fk-actions',
            'Uses the cache models, whose JOINED inheritance makes ORM generate ON DELETE CASCADE from cache_attraction_contact_info to its root table.',
        ],
        'Doctrine\Tests\ORM\Functional\SecondLevelCacheTest::testPostFlushFailure' => [
            'fk-actions',
            'Uses the cache models, whose JOINED inheritance makes ORM generate ON DELETE CASCADE from cache_attraction_contact_info to its root table.',
        ],
        'Doctrine\Tests\ORM\Functional\SecondLevelCacheTest::testPostRemoveFailure' => [
            'fk-actions',
            'Uses the cache models, whose JOINED inheritance makes ORM generate ON DELETE CASCADE from cache_attraction_contact_info to its root table.',
        ],
        'Doctrine\Tests\ORM\Functional\SecondLevelCacheTest::testPostUpdateFailure' => [
            'fk-actions',
            'Uses the cache models, whose JOINED inheritance makes ORM generate ON DELETE CASCADE from cache_attraction_contact_info to its root table.',
        ],
        'Doctrine\Tests\ORM\Functional\SecondLevelCacheTest::testPutAndLoadEntities' => [
            'fk-actions',
            'Uses the cache models, whose JOINED inheritance makes ORM generate ON DELETE CASCADE from cache_attraction_contact_info to its root table.',
        ],
        'Doctrine\Tests\ORM\Functional\SecondLevelCacheTest::testPutOnPersist' => [
            'fk-actions',
            'Uses the cache models, whose JOINED inheritance makes ORM generate ON DELETE CASCADE from cache_attraction_contact_info to its root table.',
        ],
        'Doctrine\Tests\ORM\Functional\SecondLevelCacheTest::testRemoveEntities' => [
            'fk-actions',
            'Uses the cache models, whose JOINED inheritance makes ORM generate ON DELETE CASCADE from cache_attraction_contact_info to its root table.',
        ],
        'Doctrine\Tests\ORM\Functional\SecondLevelCacheTest::testUpdateEntities' => [
            'fk-actions',
            'Uses the cache models, whose JOINED inheritance makes ORM generate ON DELETE CASCADE from cache_attraction_contact_info to its root table.',
        ],
        'Doctrine\Tests\ORM\Functional\SingleTableCompositeKeyTest::testInsertWithCompositeKey' => [
            'fk-actions',
            'Uses JOINED inheritance, for which ORM generates ON DELETE CASCADE from JoinedChildClass to its root table.',
        ],
        'Doctrine\Tests\ORM\Functional\SingleTableCompositeKeyTest::testUpdateWithCompositeKey' => [
            'fk-actions',
            'Uses JOINED inheritance, for which ORM generates ON DELETE CASCADE from JoinedChildClass to its root table.',
        ],
        'Doctrine\Tests\ORM\Functional\SingleTableInheritanceTest::testChildClassLifecycleRemove' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\SingleTableInheritanceTest::testChildClassLifecycleUpdate' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\SingleTableInheritanceTest::testDeleteByBaseClassCondition' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\SingleTableInheritanceTest::testDeleteByChildClassCondition' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\SingleTableInheritanceTest::testDeleteJoinTableRecords' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\SingleTableInheritanceTest::testEagerLoadInheritanceHierarchy' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\SingleTableInheritanceTest::testFindAllForAbstractBaseClass' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\SingleTableInheritanceTest::testFindAllForChildClass' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\SingleTableInheritanceTest::testFindByAssociation' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\SingleTableInheritanceTest::testFindForAbstractBaseClass' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\SingleTableInheritanceTest::testGetReferenceEntityWithSubclasses' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\SingleTableInheritanceTest::testInheritanceMatching' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\SingleTableInheritanceTest::testMatchingNonObjectOnAssocationThrowsException' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\SingleTableInheritanceTest::testPersistChildOfBaseClass' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\SingleTableInheritanceTest::testPersistDeepChildOfBaseClass' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\SingleTableInheritanceTest::testQueryBaseClassWithJoin' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\SingleTableInheritanceTest::testQueryChildClassWithCondition' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\SingleTableInheritanceTest::testQueryForAbstractBaseClass' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\SingleTableInheritanceTest::testQueryForChildClass' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\SingleTableInheritanceTest::testQueryScalarWithDiscriminatorValue' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\SingleTableInheritanceTest::testUpdateBaseClassWithCondition' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\SingleTableInheritanceTest::testUpdateChildClassWithCondition' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\DDC1041Test::testGrabWrongSubtypeReturnsNull' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\DDC1163Test::testIssue' => [
            'fk-actions',
            'Uses a many-to-many association whose default join columns ORM declares with ON DELETE CASCADE (DDC1163SpecialProduct).',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\DDC144Test::testIssue' => [
            'fk-actions',
            'Uses a many-to-many association whose default join columns ORM declares with ON DELETE CASCADE (ddc144_operands).',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\DDC1454Test::testFailingCase' => [
            'fk-actions',
            'Uses a many-to-many association whose default join columns ORM declares with ON DELETE CASCADE (DDC1454Picture).',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\DDC163Test::testQueryWithOrConditionUsingTwoRelationOnSameEntity' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\DDC1654Test::testManyToManyClearCollectionOrphanRemoval' => [
            'fk-actions',
            'Uses a many-to-many association whose default join columns ORM declares with ON DELETE CASCADE (ddc1654post_ddc1654comment).',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\DDC1654Test::testManyToManyClearCollectionReAddOrphanRemoval' => [
            'fk-actions',
            'Uses a many-to-many association whose default join columns ORM declares with ON DELETE CASCADE (ddc1654post_ddc1654comment).',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\DDC1654Test::testManyToManyRemoveElementFromCollectionOrphanRemoval' => [
            'fk-actions',
            'Uses a many-to-many association whose default join columns ORM declares with ON DELETE CASCADE (ddc1654post_ddc1654comment).',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\DDC1654Test::testManyToManyRemoveElementFromReAddToCollectionOrphanRemoval' => [
            'fk-actions',
            'Uses a many-to-many association whose default join columns ORM declares with ON DELETE CASCADE (ddc1654post_ddc1654comment).',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\DDC1654Test::testManyToManyRemoveFromCollectionOrphanRemoval' => [
            'fk-actions',
            'Uses a many-to-many association whose default join columns ORM declares with ON DELETE CASCADE (ddc1654post_ddc1654comment).',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\DDC168Test::testJoinedSubclassPersisterRequiresSpecificOrderOfMetadataReflFieldsArray' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\DDC1787Test::testIssue' => [
            'fk-actions',
            'Uses a many-to-many association whose default join columns ORM declares with ON DELETE CASCADE (DDC1787Bar).',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\DDC1995Test::testIssue' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\DDC1995Test::testQueryCache' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\DDC2012Test::testIssue' => [
            'fk-actions',
            'Uses a many-to-many association whose default join columns ORM declares with ON DELETE CASCADE (ddc2010_item_person).',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\DDC2090Test::testIssue' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\DDC2346Test::testIssue' => [
            'fk-actions',
            'Uses a many-to-many association whose default join columns ORM declares with ON DELETE CASCADE (DDC2346Baz).',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\DDC2575Test::testHydrationIssue' => [
            'fk-actions',
            'Uses a many-to-many association whose default join columns ORM declares with ON DELETE CASCADE (DDC2575A).',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\DDC258Test::testIssue' => [
            'fk-actions',
            'Uses a many-to-many association whose default join columns ORM declares with ON DELETE CASCADE (DDC258Class1).',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\DDC2602Test::testPostLoadListenerShouldBeAbleToRunQueries' => [
            'fk-actions',
            'Uses a many-to-many association whose default join columns ORM declares with ON DELETE CASCADE (DDC2602BiographyFieldChoice).',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\DDC2775Test::testIssueCascadeRemove' => [
            'fk-actions',
            'Uses a many-to-many association whose default join columns ORM declares with ON DELETE CASCADE (ddc2775_admin_role).',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\DDC279Test::testDDC279' => [
            'fk-actions',
            'Uses a many-to-many association whose default join columns ORM declares with ON DELETE CASCADE (DDC279EntityX).',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\DDC2943Test::testIssue' => [
            'fk-actions',
            'Uses the cache models, whose JOINED inheritance makes ORM generate ON DELETE CASCADE from cache_attraction_contact_info to its root table.',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\DDC2943Test::testIssueNonFetchJoin' => [
            'fk-actions',
            'Uses the cache models, whose JOINED inheritance makes ORM generate ON DELETE CASCADE from cache_attraction_contact_info to its root table.',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\DDC3170Test::testIssue' => [
            'fk-actions',
            'Uses a many-to-many association whose default join columns ORM declares with ON DELETE CASCADE (DDC3170ProductJoined).',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\DDC3223Test::testIssueGetId' => [
            'fk-actions',
            'Uses a many-to-many association whose default join columns ORM declares with ON DELETE CASCADE (ddc3223_journalist).',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\DDC331Test::testSelectFieldOnRootEntity' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\DDC3597Test::testSaveImageEntity' => [
            'fk-actions',
            'Uses a many-to-many association whose default join columns ORM declares with ON DELETE CASCADE (DDC3597Media).',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\DDC3644Test::testIssueWithJoinedEntity' => [
            'fk-actions',
            'Uses JOINED inheritance, for which ORM generates ON DELETE CASCADE from DDC3644Pet to its root table.',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\DDC3644Test::testIssueWithRegularEntity' => [
            'fk-actions',
            'Uses JOINED inheritance, for which ORM generates ON DELETE CASCADE from DDC3644Pet to its root table.',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\DDC3719Test::testCriteriaOnNotOwningSide' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\DDC3967Test::testIdentifierCachedWithProperType' => [
            'fk-actions',
            'Uses the cache models, whose JOINED inheritance makes ORM generate ON DELETE CASCADE from cache_attraction_contact_info to its root table.',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\DDC4003Test::testReadsThroughRepositorySameDataThatItWroteInCache' => [
            'fk-actions',
            'Uses the cache models, whose JOINED inheritance makes ORM generate ON DELETE CASCADE from cache_attraction_contact_info to its root table.',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\DDC422Test::testIssue' => [
            'fk-actions',
            'Uses a many-to-many association whose default join columns ORM declares with ON DELETE CASCADE (DDC422Customer).',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\DDC440Test::testOriginalEntityDataEmptyWhenProxyLoadedFromTwoAssociations' => [
            'fk-actions',
            'Uses a many-to-many association whose default join columns ORM declares with ON DELETE CASCADE (client).',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\DDC448Test::testIssue' => [
            'fk-actions',
            'Uses a many-to-many association whose default join columns ORM declares with ON DELETE CASCADE (DDC448MainTable).',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\DDC493Test::testIssue' => [
            'fk-actions',
            'Uses a many-to-many association whose default join columns ORM declares with ON DELETE CASCADE (DDC493Distributor).',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\DDC512Test::testIssue' => [
            'fk-actions',
            'Uses a many-to-many association whose default join columns ORM declares with ON DELETE CASCADE (DDC512OfferItem).',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\DDC513Test::testIssue' => [
            'fk-actions',
            'Uses a many-to-many association whose default join columns ORM declares with ON DELETE CASCADE (DDC513OfferItem).',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\DDC6303Test::testEmptyValuesInJoinedInheritance' => [
            'fk-actions',
            'Uses a many-to-many association whose default join columns ORM declares with ON DELETE CASCADE (DDC6303ChildA).',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\DDC6303Test::testMixedTypeHydratedCorrectlyInJoinedInheritance' => [
            'fk-actions',
            'Uses a many-to-many association whose default join columns ORM declares with ON DELETE CASCADE (DDC6303ChildA).',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\DDC6558Test::testEmployeeIsPopulated' => [
            'fk-actions',
            'Uses a many-to-many association whose default join columns ORM declares with ON DELETE CASCADE (DDC6558Employee).',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\DDC832Test::testQuotedTableBasicRemove' => [
            'fk-actions',
            'Uses a many-to-many association whose default join columns ORM declares with ON DELETE CASCADE (TREE_INDEX).',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\DDC832Test::testQuotedTableBasicUpdate' => [
            'fk-actions',
            'Uses a many-to-many association whose default join columns ORM declares with ON DELETE CASCADE (TREE_INDEX).',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\DDC832Test::testQuotedTableJoinedChildRemove' => [
            'fk-actions',
            'Uses a many-to-many association whose default join columns ORM declares with ON DELETE CASCADE (TREE_INDEX).',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\DDC832Test::testQuotedTableJoinedChildUpdate' => [
            'fk-actions',
            'Uses a many-to-many association whose default join columns ORM declares with ON DELETE CASCADE (TREE_INDEX).',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\DDC832Test::testQuotedTableJoinedRemove' => [
            'fk-actions',
            'Uses a many-to-many association whose default join columns ORM declares with ON DELETE CASCADE (TREE_INDEX).',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\DDC832Test::testQuotedTableJoinedUpdate' => [
            'fk-actions',
            'Uses a many-to-many association whose default join columns ORM declares with ON DELETE CASCADE (TREE_INDEX).',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\DDC837Test::testIssue' => [
            'fk-actions',
            'Uses a many-to-many association whose default join columns ORM declares with ON DELETE CASCADE (DDC837Class1).',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\DDC933Test::testLockCTIClass' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\DDC960Test::testUpdateRootVersion' => [
            'fk-actions',
            'Uses a many-to-many association whose default join columns ORM declares with ON DELETE CASCADE (DDC960Child).',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\DDC992Test::testIssue' => [
            'fk-actions',
            'Uses a many-to-many association whose default join columns ORM declares with ON DELETE CASCADE (DDC992Child).',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\DDC992Test::testOneToManyChild' => [
            'fk-actions',
            'Uses a many-to-many association whose default join columns ORM declares with ON DELETE CASCADE (DDC992Child).',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\GH10288Test::testEnumDiscriminatorWithCustomEnumType' => [
            'fk-actions',
            'Uses a many-to-many association whose default join columns ORM declares with ON DELETE CASCADE (GH10288BossWithEnumType).',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\GH10288Test::testEnumDiscriminatorWithEnumType' => [
            'fk-actions',
            'Uses a many-to-many association whose default join columns ORM declares with ON DELETE CASCADE (GH10288BossWithEnumType).',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\GH10531Test::testDeletes' => [
            'fk-actions',
            'Uses a many-to-many association whose default join columns ORM declares with ON DELETE CASCADE (gh10531_b).',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\GH10531Test::testInserts' => [
            'fk-actions',
            'Uses a many-to-many association whose default join columns ORM declares with ON DELETE CASCADE (gh10531_b).',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\GH10532Test::testDeletes' => [
            'fk-actions',
            'Uses a many-to-many association whose default join columns ORM declares with ON DELETE CASCADE (gh10532_b).',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\GH10532Test::testInserts' => [
            'fk-actions',
            'Uses a many-to-many association whose default join columns ORM declares with ON DELETE CASCADE (gh10532_b).',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\GH10566Test::testInsertion' => [
            'fk-actions',
            'Uses a many-to-many association whose default join columns ORM declares with ON DELETE CASCADE (gh10566_a).',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\GH10566Test::testRemoval' => [
            'fk-actions',
            'Uses a many-to-many association whose default join columns ORM declares with ON DELETE CASCADE (gh10566_a).',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\GH10752Test::testThrowExceptionWhenRemovingPromotionThatIsInUse' => [
            'fk-actions',
            'Its mapping declares ON DELETE CASCADE on order_promotion.',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\GH10752Test::testThrowExceptionWhenRemovingPromotionThatIsInUseAndOrderIsNotInMemory' => [
            'fk-actions',
            'Its mapping declares ON DELETE CASCADE on order_promotion.',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\GH10912Test::testIssue' => [
            'fk-actions',
            'Uses a many-to-many association whose default join columns ORM declares with ON DELETE CASCADE (GH10912User).',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\GH10913Test::testExample1' => [
            'fk-actions',
            'Its mapping declares ON DELETE CASCADE on GH10913Entity.',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\GH10913Test::testExample2' => [
            'fk-actions',
            'Its mapping declares ON DELETE CASCADE on GH10913Entity.',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\GH10913Test::testExample3' => [
            'fk-actions',
            'Its mapping declares ON DELETE CASCADE on GH10913Entity.',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\GH10913Test::testExample4' => [
            'fk-actions',
            'Its mapping declares ON DELETE CASCADE on GH10913Entity.',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\GH12505\GH12505Test::testHydrateManyToManyIndexedByInheritedIdAfterFilterChange' => [
            'fk-actions',
            'Uses a many-to-many association whose default join columns ORM declares with ON DELETE CASCADE (gh_12505_car).',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\GH12674\GH12674PartialCollectionTest::testFetchJoiningACollectionIntoPartialObjectsDoesNotInitializeThem' => [
            'fk-actions',
            'Uses a many-to-many association whose default join columns ORM declares with ON DELETE CASCADE (gh12674_post_tag).',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\GH5742Test::testManyToManyCollectionUpdateBeforeRemoval' => [
            'fk-actions',
            'Uses a many-to-many association whose default join columns ORM declares with ON DELETE CASCADE (gh5742person_gh5742toothpastebrand).',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\GH5742Test::testUpdateOneToOneToNewEntityBeforePreviousEntityCanBeRemoved' => [
            'fk-actions',
            'Uses a many-to-many association whose default join columns ORM declares with ON DELETE CASCADE (gh5742person_gh5742toothpastebrand).',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\GH5988Test::testDelete' => [
            'fk-actions',
            'Uses a many-to-many association whose default join columns ORM declares with ON DELETE CASCADE (GH5988CustomIdObjectTypeChild).',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\GH5998Test::testIssue' => [
            'fk-actions',
            'Uses a many-to-many association whose default join columns ORM declares with ON DELETE CASCADE (GH5998JTIChild).',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\GH6029Test::testManyToManyAssociation' => [
            'fk-actions',
            'Uses a many-to-many association whose default join columns ORM declares with ON DELETE CASCADE (gh6029user_gh6029group).',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\GH6029Test::testOneToManyAssociation' => [
            'fk-actions',
            'Uses a many-to-many association whose default join columns ORM declares with ON DELETE CASCADE (gh6029user_gh6029group).',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\GH6141Test::testEnumDiscriminatorsShouldBeConvertedToString' => [
            'fk-actions',
            'Uses a many-to-many association whose default join columns ORM declares with ON DELETE CASCADE (GH6141Boss).',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\GH6464Test::testIssue' => [
            'fk-actions',
            'Uses a many-to-many association whose default join columns ORM declares with ON DELETE CASCADE (GH6464Author).',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\GH6937Test::testPhoneNumberIsPopulatedWithFind' => [
            'fk-actions',
            'Uses JOINED inheritance, for which ORM generates ON DELETE CASCADE from GH6937Employee to its root table.',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\GH6937Test::testPhoneNumberIsPopulatedWithQueryBuilder' => [
            'fk-actions',
            'Uses JOINED inheritance, for which ORM generates ON DELETE CASCADE from GH6937Employee to its root table.',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\GH6937Test::testPhoneNumberIsPopulatedWithQueryBuilderUsingSimpleObjectHydrator' => [
            'fk-actions',
            'Uses JOINED inheritance, for which ORM generates ON DELETE CASCADE from GH6937Employee to its root table.',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\GH7012Test::testUpdateEntityWithIdentifierAssociationWithQuotedJoinColumn' => [
            'fk-actions',
            'Uses a many-to-many association whose default join columns ORM declares with ON DELETE CASCADE (quote-user-data).',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\GH7039Test::testCountWithInstanceOfParameter' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\GH7512Test::testFindEntityByAssociationPropertyJoinedChildWithClearMetadata' => [
            'fk-actions',
            'Uses a many-to-many association whose default join columns ORM declares with ON DELETE CASCADE (GH7512EntityB).',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\GH7717Test::testManyToManyPersisterIsNullComparison' => [
            'fk-actions',
            'Uses a many-to-many association whose default join columns ORM declares with ON DELETE CASCADE (gh7717parent_gh7717child).',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\GH7737Test::memberOfCriteriaShouldBeCompatibleWithQueryBuilder' => [
            'fk-actions',
            'Uses a many-to-many association whose default join columns ORM declares with ON DELETE CASCADE (gh7737person_gh7737group).',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\GH8055Test::testNumericDescriminatorColumn' => [
            'fk-actions',
            'Uses a many-to-many association whose default join columns ORM declares with ON DELETE CASCADE (GH8055SubClass).',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\GH8127Test::testLoadFieldsFromAllClassesInHierarchy' => [
            'fk-actions',
            'Uses a many-to-many association whose default join columns ORM declares with ON DELETE CASCADE (GH8127Middle).',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\GH8415Test::testAssociationIsBasedOnBaseClass' => [
            'fk-actions',
            'Uses a many-to-many association whose default join columns ORM declares with ON DELETE CASCADE (GH8415LeafClass).',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\GH8443Test::testJoinRootEntityWithForcePartialLoad' => [
            'fk-actions',
            'Uses the company models: CompanyPerson declares ON DELETE CASCADE, and their JOINED inheritance makes ORM generate more.',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\GH9109Test::testIssue' => [
            'fk-actions',
            'Uses a many-to-many association whose default join columns ORM declares with ON DELETE CASCADE (gh9109product_gh9109user).',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\GH9192Test::testIssue' => [
            'fk-actions',
            'Uses a many-to-many association whose default join columns ORM declares with ON DELETE CASCADE (GH9192A).',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\GH9467\GH9467Test::testChildNonInsertableColumnInsert' => [
            'fk-actions',
            'Uses a many-to-many association whose default join columns ORM declares with ON DELETE CASCADE (joined_inheritance_child).',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\GH9467\GH9467Test::testChildNonUpdatableColumnInsert' => [
            'fk-actions',
            'Uses a many-to-many association whose default join columns ORM declares with ON DELETE CASCADE (joined_inheritance_child).',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\GH9467\GH9467Test::testChildNonWritableColumnInsert' => [
            'fk-actions',
            'Uses a many-to-many association whose default join columns ORM declares with ON DELETE CASCADE (joined_inheritance_child).',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\GH9467\GH9467Test::testChildWritableColumnInsert' => [
            'fk-actions',
            'Uses a many-to-many association whose default join columns ORM declares with ON DELETE CASCADE (joined_inheritance_child).',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\GH9467\GH9467Test::testRootColumnsInsert' => [
            'fk-actions',
            'Uses a many-to-many association whose default join columns ORM declares with ON DELETE CASCADE (joined_inheritance_child).',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\GH9807Test::testHydrateJoinedCollectionWithFirstNullishRow' => [
            'fk-actions',
            'Uses a many-to-many association whose default join columns ORM declares with ON DELETE CASCADE (gh9807main_gh9807join).',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\Issue5989Test::testSimpleArrayTypeHydratedCorrectlyInJoinedInheritance' => [
            'fk-actions',
            'Uses a many-to-many association whose default join columns ORM declares with ON DELETE CASCADE (issue5989_employees).',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\Issue9300Test::testPersistedCollectionIsPresentInOriginalDataAfterFlush' => [
            'fk-actions',
            'Uses a many-to-many association whose default join columns ORM declares with ON DELETE CASCADE (issue9300child_issue9300parent).',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\Issue9300Test::testPersistingCollectionAfterFlushWorksAsExpected' => [
            'fk-actions',
            'Uses a many-to-many association whose default join columns ORM declares with ON DELETE CASCADE (issue9300child_issue9300parent).',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\SwitchContextWithFilterAndIndexedRelation\ChangeFiltersTest::testIndexAliasUpdatedWithUpdatedFilter' => [
            'fk-actions',
            'Uses a many-to-many association whose default join columns ORM declares with ON DELETE CASCADE (company_category).',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\Ticket4646InstanceOfAbstractTest::testInstanceOf' => [
            'fk-actions',
            'Uses a many-to-many association whose default join columns ORM declares with ON DELETE CASCADE (instance_of_abstract_test_employee).',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\Ticket4646InstanceOfMultiLevelTest::testInstanceOf' => [
            'fk-actions',
            'Uses a many-to-many association whose default join columns ORM declares with ON DELETE CASCADE (instance_of_multi_level_employee).',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\Ticket4646InstanceOfParametricTest::testInstanceOf' => [
            'fk-actions',
            'Uses a many-to-many association whose default join columns ORM declares with ON DELETE CASCADE (instance_of_parametric_employee).',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\Ticket4646InstanceOfTest::testInstanceOf' => [
            'fk-actions',
            'Uses a many-to-many association whose default join columns ORM declares with ON DELETE CASCADE (instance_of_test_employee).',
        ],
        'Doctrine\Tests\ORM\Functional\Ticket\Ticket4646InstanceOfWithMultipleParametersTest::testInstanceOf' => [
            'fk-actions',
            'Uses a many-to-many association whose default join columns ORM declares with ON DELETE CASCADE (instance_of_test_multiple_employee).',
        ],
        'Doctrine\Tests\ORM\Tools\Console\Command\MappingDescribeCommandTest::testComplete' => [
            'fk-actions',
            'Uses the cache models, whose JOINED inheritance makes ORM generate ON DELETE CASCADE from cache_attraction_contact_info to its root table.',
        ],
        'Doctrine\Tests\ORM\Utility\IdentifierFlattenerTest::testFlattenIdentifierWithMutlipleIds' => [
            'fk-actions',
            'Its City model references cache_state, which only the cache model set creates, and that set uses JOINED inheritance, for which ORM generates ON DELETE CASCADE.',
        ],
        'tests\Doctrine\Tests\ORM\Functional\Ticket\DDC7969Test::testChildEntityRetrievedFromCache' => [
            'fk-actions',
            'Uses the cache models, whose JOINED inheritance makes ORM generate ON DELETE CASCADE from cache_attraction_contact_info to its root table.',
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
