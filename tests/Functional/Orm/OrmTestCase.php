<?php

declare(strict_types=1);

namespace MartinJuul\DBAL\DuckDB\Tests\Functional\Orm;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use MartinJuul\DBAL\DuckDB\Tests\Functional\FunctionalTestCase;

abstract class OrmTestCase extends FunctionalTestCase
{
    /** An entity manager over the fixture entities, whose tables the test creates itself. */
    protected static function createEntityManager(Connection $connection): EntityManager
    {
        $config = ORMSetup::createAttributeMetadataConfig([__DIR__ . '/Fixture'], true);
        $config->enableNativeLazyObjects(true);

        return new EntityManager($connection, $config);
    }

    protected static function createAccountTable(Connection $connection): void
    {
        $connection->executeStatement('CREATE TABLE account (id INTEGER PRIMARY KEY, name VARCHAR NOT NULL)');
    }
}
