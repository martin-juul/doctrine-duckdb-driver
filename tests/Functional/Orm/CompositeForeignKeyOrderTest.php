<?php

declare(strict_types=1);

namespace MartinJuul\DBAL\DuckDB\Tests\Functional\Orm;

use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\ORM\Tools\SchemaValidator;
use MartinJuul\DBAL\DuckDB\Setup\DuckDBSetup;
use MartinJuul\DBAL\DuckDB\Tests\Functional\FunctionalTestCase;
use MartinJuul\DBAL\DuckDB\Tests\Functional\Orm\CompositeKeyFixture\Member;
use MartinJuul\DBAL\DuckDB\Tests\Functional\Orm\CompositeKeyFixture\Privilege;

/**
 * Join columns that list a composite primary key's columns in another order
 * than the key, as in ORM's DDC2252Test. DuckDB requires the key's order.
 */
final class CompositeForeignKeyOrderTest extends FunctionalTestCase
{
    public function testTheSchemaCreatesReValidatesUnchangedAndEnforcesTheKey(): void
    {
        $entityManager = self::createSchema();
        $metadata      = $entityManager->getMetadataFactory()->getAllMetadata();
        $connection    = $entityManager->getConnection();

        self::assertSame([], (new SchemaTool($entityManager))->getUpdateSchemaSql($metadata));
        self::assertTrue((new SchemaValidator($entityManager))->schemaInSyncWithMetadata());

        $connection->executeStatement('INSERT INTO member (uid, account_id) VALUES (222, 111)');
        $connection->executeStatement('INSERT INTO privilege (id) VALUES (1)');
        $connection->executeStatement(
            'INSERT INTO member_privilege (account_id, uid, privilege_id) VALUES (111, 222, 1)',
        );

        $this->expectException(ForeignKeyConstraintViolationException::class);

        $connection->executeStatement(
            'INSERT INTO member_privilege (account_id, uid, privilege_id) VALUES (222, 111, 1)',
        );
    }

    public function testCollectionChangesAreWrittenThroughTheCompositeJoinColumns(): void
    {
        $entityManager = self::createSchema();
        $member        = new Member(222, 111);

        foreach ([1, 2, 3] as $id) {
            $privilege = new Privilege($id);

            $entityManager->persist($privilege);
            $member->privileges->add($privilege);
        }

        $entityManager->persist($member);
        $entityManager->flush();
        $entityManager->clear();

        $identifier = ['uid' => 222, 'accountId' => 111];
        $found      = $entityManager->find(Member::class, $identifier);

        self::assertNotNull($found);
        self::assertCount(3, $found->privileges);

        $found->privileges->remove(0);
        $entityManager->flush();
        $entityManager->clear();

        $found = $entityManager->find(Member::class, $identifier);

        self::assertNotNull($found);
        self::assertCount(2, $found->privileges);
    }

    private static function createSchema(): EntityManager
    {
        $configuration = ORMSetup::createAttributeMetadataConfig([__DIR__ . '/CompositeKeyFixture'], true);
        $configuration->enableNativeLazyObjects(true);

        DuckDBSetup::configureOrm($configuration);

        $entityManager = new EntityManager(self::connect(), $configuration);

        (new SchemaTool($entityManager))->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

        return $entityManager;
    }
}
