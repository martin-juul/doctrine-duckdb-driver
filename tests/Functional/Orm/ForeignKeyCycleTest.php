<?php

declare(strict_types=1);

namespace MartinJuul\DBAL\DuckDB\Tests\Functional\Orm;

use Doctrine\Common\EventManager;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\ORM\Tools\SchemaValidator;
use MartinJuul\DBAL\DuckDB\Setup\DuckDBSetup;
use MartinJuul\DBAL\DuckDB\Tests\Functional\FunctionalTestCase;
use MartinJuul\DBAL\DuckDB\Tests\Functional\Orm\CycleFixture\Post;
use MartinJuul\DBAL\DuckDB\Tests\Functional\Orm\CycleFixture\User;

/**
 * AE12: `User.lastPost` (nullable) and `Post.author` (required) reference
 * each other. The schema tool leaves out the nullable key and creates the
 * other.
 */
final class ForeignKeyCycleTest extends FunctionalTestCase
{
    public function testSchemaToolLeavesOutTheNullableKeyAndReValidatesUnchanged(): void
    {
        $entityManager = self::createSchema(self::connect());
        $metadata      = $entityManager->getMetadataFactory()->getAllMetadata();

        self::assertSame(
            ['cycle_post(author_id)->cycle_user'],
            $entityManager->getConnection()->fetchFirstColumn(
                "SELECT table_name || '(' || array_to_string(constraint_column_names, ', ') || ')->' || referenced_table"
                    . " FROM duckdb_constraints() WHERE constraint_type = 'FOREIGN KEY' ORDER BY 1",
            ),
        );

        self::assertSame([], (new SchemaTool($entityManager))->getUpdateSchemaSql($metadata));
        self::assertTrue((new SchemaValidator($entityManager))->schemaInSyncWithMetadata());
    }

    public function testTheAuthorKeyIsEnforcedAndTheLastPostIsApplicationEnforced(): void
    {
        $connection = self::createSchema(self::connect())->getConnection();

        $connection->executeStatement("INSERT INTO cycle_user (id, name, last_post_id) VALUES (1, 'Ann', 99)");

        self::assertSame(99, $connection->fetchOne('SELECT last_post_id FROM cycle_user'));

        $this->expectException(ForeignKeyConstraintViolationException::class);

        $connection->executeStatement("INSERT INTO cycle_post (id, title, author_id) VALUES (1, 'A', 98)");
    }

    /** ORM inserts the user before the post that requires it; the left-out key does not get in the way. */
    public function testAUserAndAPostPersistInOneFlushAndLoad(): void
    {
        $entityManager = self::createSchema(self::connect());
        $user          = new User('Ann');
        $post          = new Post('Hello', $user);

        $entityManager->persist($post);
        $entityManager->persist($user);
        $entityManager->flush();
        $entityManager->clear();

        $found = $entityManager->find(Post::class, $post->id);

        self::assertNotNull($found);
        self::assertSame('Ann', $found->author->name);
        self::assertNull($found->author->lastPost);
    }

    public function testSchemaToolDropsTheSchema(): void
    {
        $entityManager = self::createSchema(self::connect());
        $schemaTool    = new SchemaTool($entityManager);

        $schemaTool->dropSchema($entityManager->getMetadataFactory()->getAllMetadata());

        self::assertSame([], $entityManager->getConnection()->createSchemaManager()->introspectTableNames());
    }

    /** An entity manager configured by DuckDBSetup, with the fixture schema created by ORM's schema tool. */
    private static function createSchema(Connection $connection): EntityManager
    {
        $configuration = ORMSetup::createAttributeMetadataConfig([__DIR__ . '/CycleFixture'], true);
        $configuration->enableNativeLazyObjects(true);

        DuckDBSetup::configureOrm($configuration);

        $eventManager = new EventManager();

        DuckDBSetup::registerListeners($eventManager);

        $entityManager = new EntityManager($connection, $configuration, $eventManager);

        (new SchemaTool($entityManager))->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

        return $entityManager;
    }
}
