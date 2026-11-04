<?php

declare(strict_types=1);

namespace MartinJuul\DBAL\DuckDB\Tests\Functional\Orm;

use Doctrine\ORM\Tools\SchemaTool;
use MartinJuul\DBAL\DuckDB\Tests\Functional\Orm\Fixture\Measurement;
use MartinJuul\DBAL\DuckDB\Types\TypeRegistrar;

final class NestedTypesEntityTest extends OrmTestCase
{
    public function testEntityWithNestedFieldsPersistsAndHydrates(): void
    {
        TypeRegistrar::register();

        $connection    = self::connect();
        $entityManager = self::createEntityManager($connection);
        $metadata      = [$entityManager->getClassMetadata(Measurement::class)];
        $schemaTool    = new SchemaTool($entityManager);

        $schemaTool->createSchema($metadata);

        self::assertSame(
            [
                'readings' => 'INTEGER[]',
                'origin' => 'STRUCT(station VARCHAR, elevation INTEGER)',
                'counts' => 'MAP(VARCHAR, INTEGER)',
            ],
            $connection->fetchAllKeyValue(
                "SELECT column_name, data_type FROM duckdb_columns() WHERE table_name = 'measurement' AND column_name <> 'id' ORDER BY column_index",
            ),
        );

        $entityManager->persist(new Measurement(1, [3, 1, 2], ['station' => 'north', 'elevation' => 120], ['rain' => 4, 'snow' => 0]));
        $entityManager->persist(new Measurement(2, [], null, []));
        $entityManager->flush();
        $entityManager->clear();

        $first = $entityManager->find(Measurement::class, 1);
        self::assertNotNull($first);
        self::assertSame([3, 1, 2], $first->readings);
        self::assertSame(['station' => 'north', 'elevation' => 120], $first->origin);
        self::assertSame(['rain' => 4, 'snow' => 0], $first->counts);

        $second = $entityManager->find(Measurement::class, 2);
        self::assertNotNull($second);
        self::assertSame([], $second->readings);
        self::assertNull($second->origin);
        self::assertSame([], $second->counts);

        self::assertSame([], $schemaTool->getUpdateSchemaSql($metadata));
    }
}
