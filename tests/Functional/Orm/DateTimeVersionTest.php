<?php

declare(strict_types=1);

namespace MartinJuul\DBAL\DuckDB\Tests\Functional\Orm;

use DateTime;
use Doctrine\ORM\Tools\SchemaTool;
use MartinJuul\DBAL\DuckDB\Tests\Functional\Orm\Fixture\VersionedNote;

final class DateTimeVersionTest extends OrmTestCase
{
    /**
     * DBAL formats datetimes to the second. The version column keeps seconds
     * only, so the version the ORM reads back after an insert matches the
     * stored one in the next UPDATE's WHERE clause.
     */
    public function testEntityWithDateTimeVersionUpdatesAfterReload(): void
    {
        $connection    = self::connect();
        $entityManager = self::createEntityManager($connection);
        $metadata      = [$entityManager->getClassMetadata(VersionedNote::class)];
        $schemaTool    = new SchemaTool($entityManager);

        $schemaTool->createSchema($metadata);

        $entityManager->persist(new VersionedNote(1, new DateTime('2026-01-02 03:04:05')));
        $entityManager->flush();
        $entityManager->clear();

        $note = $entityManager->find(VersionedNote::class, 1);
        self::assertNotNull($note);
        self::assertNotNull($note->version);

        $note->lastUpdate = new DateTime('2026-01-02 03:04:06');
        $entityManager->flush();
        $entityManager->clear();

        self::assertSame(
            '2026-01-02 03:04:06',
            $connection->fetchOne('SELECT lastUpdate FROM versioned_note WHERE id = 1'),
        );

        self::assertSame(
            ['version' => 'TIMESTAMP_S', 'id' => 'INTEGER', 'lastUpdate' => 'TIMESTAMP_S'],
            $connection->fetchAllKeyValue(
                "SELECT column_name, data_type FROM duckdb_columns() WHERE table_name = 'versioned_note' ORDER BY column_index",
            ),
        );

        self::assertSame([], $schemaTool->getUpdateSchemaSql($metadata));
    }
}
