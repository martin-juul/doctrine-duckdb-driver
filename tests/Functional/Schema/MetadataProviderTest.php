<?php

declare(strict_types=1);

namespace MartinJuul\DBAL\DuckDB\Tests\Functional\Schema;

use Closure;
use Doctrine\DBAL\Schema\Exception\UnsupportedName;
use Doctrine\DBAL\Schema\Metadata\MetadataProvider;
use MartinJuul\DBAL\DuckDB\Tests\Functional\FunctionalTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class MetadataProviderTest extends FunctionalTestCase
{
    /** @return iterable<string, array{Closure(MetadataProvider): iterable<mixed>}> */
    public static function withoutSchemaName(): iterable
    {
        yield 'columns' => [
            static fn (MetadataProvider $provider): iterable => $provider->getTableColumnsForTable(null, 'orders'),
        ];

        yield 'indexes' => [
            static fn (MetadataProvider $provider): iterable => $provider->getIndexColumnsForTable(null, 'orders'),
        ];

        yield 'primary key constraint' => [
            static fn (MetadataProvider $provider): iterable => $provider
                ->getPrimaryKeyConstraintColumnsForTable(null, 'orders'),
        ];

        yield 'unique constraints' => [
            static fn (MetadataProvider $provider): iterable => $provider
                ->getUniqueConstraintColumnsForTable(null, 'orders'),
        ];

        yield 'foreign key constraints' => [
            static fn (MetadataProvider $provider): iterable => $provider
                ->getForeignKeyConstraintColumnsForTable(null, 'orders'),
        ];

        yield 'options' => [
            static fn (MetadataProvider $provider): iterable => $provider->getTableOptionsForTable(null, 'orders'),
        ];
    }

    /**
     * Upstream: SchemaManagerTest::testIntrospectionWithoutSchemaNameWithSchemaSupport.
     * The platform supports schemas, so a per-table method requires one, and
     * refuses null when it is called, before any row is read.
     *
     * @param Closure(MetadataProvider): iterable<mixed> $introspect
     */
    #[DataProvider('withoutSchemaName')]
    public function testPerTableIntrospectionRequiresASchemaName(Closure $introspect): void
    {
        $connection = self::connect();
        $platform   = $connection->getDatabasePlatform();

        self::assertTrue($platform->supportsSchemas());

        $provider = $platform->createMetadataProvider($connection);

        $this->expectException(UnsupportedName::class);

        $introspect($provider);
    }
}
