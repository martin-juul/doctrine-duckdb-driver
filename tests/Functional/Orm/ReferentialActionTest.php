<?php

declare(strict_types=1);

namespace MartinJuul\DBAL\DuckDB\Tests\Functional\Orm;

use Doctrine\Common\EventManager;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\ORM\Tools\SchemaValidator;
use MartinJuul\DBAL\DuckDB\Setup\DuckDBSetup;
use MartinJuul\DBAL\DuckDB\Tests\Functional\FunctionalTestCase;
use MartinJuul\DBAL\DuckDB\Tests\Functional\Orm\ReferentialActionFixture\Author;
use MartinJuul\DBAL\DuckDB\Tests\Functional\Orm\ReferentialActionFixture\Book;
use MartinJuul\DBAL\DuckDB\Tests\Functional\Orm\ReferentialActionFixture\Car;
use MartinJuul\DBAL\DuckDB\Tests\Functional\Orm\ReferentialActionFixture\Publisher;
use MartinJuul\DBAL\DuckDB\Tests\Functional\Orm\ReferentialActionFixture\SportsCar;
use MartinJuul\DBAL\DuckDB\Tests\Functional\Orm\ReferentialActionFixture\Tag;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;

/**
 * AE7: ORM schemas whose foreign keys request ON DELETE CASCADE, from an
 * explicit `onDelete`, JOINED inheritance and a default many-to-many
 * association.
 */
final class ReferentialActionTest extends FunctionalTestCase
{
    public function testSchemaToolCreatesTheSchemaWithoutTheCascadingKeysAndReValidatesUnchanged(): void
    {
        $entityManager = self::createSchema(self::connect());
        $metadata      = $entityManager->getMetadataFactory()->getAllMetadata();

        self::assertSame(
            ['book->publisher'],
            $entityManager->getConnection()->fetchFirstColumn(
                "SELECT table_name || '->' || referenced_table FROM duckdb_constraints()"
                    . " WHERE constraint_type = 'FOREIGN KEY' ORDER BY 1",
            ),
        );

        self::assertSame([], (new SchemaTool($entityManager))->getUpdateSchemaSql($metadata));
        self::assertTrue((new SchemaValidator($entityManager))->schemaInSyncWithMetadata());
    }

    public function testForeignKeysWithoutAReferentialActionAreEnforced(): void
    {
        $connection = self::createSchema(self::connect())->getConnection();

        $connection->executeStatement("INSERT INTO author (id, name) VALUES (1, 'Ann')");
        $connection->executeStatement("INSERT INTO publisher (id, name) VALUES (1, 'Pub')");
        $connection->executeStatement("INSERT INTO book (id, title, author_id, publisher_id) VALUES (1, 'A', 99, 1)");

        $this->expectException(ForeignKeyConstraintViolationException::class);

        $connection->executeStatement("INSERT INTO book (id, title, author_id, publisher_id) VALUES (2, 'B', 1, 99)");
    }

    public function testRemovingAJoinedEntityLeavesNoRowsInItsSubclassTables(): void
    {
        $entityManager = self::createSchema(self::connect());
        $connection    = $entityManager->getConnection();
        $roadster      = new SportsCar('Roadster', 2, 250);
        $hatchback     = new Car('Hatchback', 5);
        $coupe         = new Car('Coupe', 3);

        $entityManager->persist($roadster);
        $entityManager->persist($hatchback);
        $entityManager->persist($coupe);
        $entityManager->flush();
        $entityManager->clear();

        $foundRoadster = $entityManager->find(SportsCar::class, $roadster->id);
        $foundCoupe    = $entityManager->find(Car::class, $coupe->id);

        self::assertNotNull($foundRoadster);
        self::assertNotNull($foundCoupe);

        $entityManager->remove($foundRoadster);
        $entityManager->remove($foundCoupe);
        $entityManager->flush();

        self::assertSame(['Hatchback'], $connection->fetchFirstColumn('SELECT name FROM vehicle'));
        self::assertSame([$hatchback->id], $connection->fetchFirstColumn('SELECT id FROM car'));
        self::assertSame(0, $connection->fetchOne('SELECT count(*) FROM sports_car'));
    }

    public function testRemovingAnEntityWithAManyToManyAssociationSucceeds(): void
    {
        $entityManager = self::createSchema(self::connect());
        $connection    = $entityManager->getConnection();
        $book          = new Book('Dune', new Author('Frank'), new Publisher('Chilton'));
        $sciFi         = new Tag('sci-fi');
        $classic       = new Tag('classic');

        $book->tags->add($sciFi);
        $book->tags->add($classic);

        $entityManager->persist($book->author);
        $entityManager->persist($book->publisher);
        $entityManager->persist($sciFi);
        $entityManager->persist($classic);
        $entityManager->persist($book);
        $entityManager->flush();
        $entityManager->clear();

        $foundBook = $entityManager->find(Book::class, $book->id);

        self::assertNotNull($foundBook);
        self::assertCount(2, $foundBook->tags);

        $entityManager->remove($foundBook);
        $entityManager->flush();

        self::assertSame(0, $connection->fetchOne('SELECT count(*) FROM book'));
        self::assertSame(2, $connection->fetchOne('SELECT count(*) FROM tag'));
    }

    /**
     * The listener acts only on DuckDB. SQLite enforces no foreign keys unless
     * enabled, so its subclass rows stay behind: no DELETE reached them.
     */
    #[RequiresPhpExtension('pdo_sqlite')]
    public function testTheListenerLeavesOtherPlatformsAlone(): void
    {
        $entityManager = self::createSchema(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]));
        $roadster      = new SportsCar('Roadster', 2, 250);

        $entityManager->persist($roadster);
        $entityManager->flush();
        $entityManager->remove($roadster);
        $entityManager->flush();

        self::assertSame(0, $entityManager->getConnection()->fetchOne('SELECT count(*) FROM vehicle'));
        self::assertSame(1, $entityManager->getConnection()->fetchOne('SELECT count(*) FROM sports_car'));
    }

    /** An entity manager configured by DuckDBSetup, with the fixture schema created by ORM's schema tool. */
    private static function createSchema(Connection $connection): EntityManager
    {
        $configuration = ORMSetup::createAttributeMetadataConfig([__DIR__ . '/ReferentialActionFixture'], true);
        $configuration->enableNativeLazyObjects(true);

        DuckDBSetup::configureOrm($configuration);

        $eventManager = new EventManager();

        DuckDBSetup::registerListeners($eventManager);

        $entityManager = new EntityManager($connection, $configuration, $eventManager);

        (new SchemaTool($entityManager))->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

        return $entityManager;
    }
}
