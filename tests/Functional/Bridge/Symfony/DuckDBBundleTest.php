<?php

declare(strict_types=1);

namespace MartinJuul\DBAL\DuckDB\Tests\Functional\Bridge\Symfony;

use Closure;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\ColumnEditor;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use MartinJuul\DBAL\DuckDB\Platform\DuckDBPlatform;
use MartinJuul\DBAL\DuckDB\Tests\Functional\Bridge\Symfony\Fixture\Entity\Note;
use MartinJuul\DBAL\DuckDB\Tests\Functional\Bridge\Symfony\Fixture\TestKernel;
use MartinJuul\DBAL\DuckDB\Tests\Functional\Fixtures\RecordingLogger;
use MartinJuul\DBAL\DuckDB\Tests\Functional\Orm\ReferentialActionFixture\SportsCar;
use MartinJuul\DBAL\DuckDB\Types\TypeRegistrar;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Filesystem\Filesystem;

use function array_column;
use function array_keys;
use function bin2hex;
use function random_bytes;
use function sys_get_temp_dir;

final class DuckDBBundleTest extends TestCase
{
    private const array MAPPINGS = [
        'Fixture' => [
            'type' => 'attribute',
            'dir' => __DIR__ . '/Fixture/Entity',
            'prefix' => 'MartinJuul\DBAL\DuckDB\Tests\Functional\Bridge\Symfony\Fixture\Entity',
            'is_bundle' => false,
        ],
    ];

    private string $directory;

    /** @var list<TestKernel> */
    private array $kernels = [];

    #[Before]
    protected function createDirectory(): void
    {
        $this->directory = sys_get_temp_dir() . '/doctrine-duckdb-driver-bundle-' . bin2hex(random_bytes(6));

        (new Filesystem())->mkdir($this->directory);
    }

    #[After]
    protected function shutDownKernelsAndRemoveDirectory(): void
    {
        foreach ($this->kernels as $kernel) {
            $kernel->shutdown();
        }

        $this->kernels = [];

        (new Filesystem())->remove($this->directory);
    }

    /** AE15: a `duckdb` URL is the only Doctrine configuration a default-ID entity needs. */
    public function testPersistsADefaultIdEntityWithASequenceValue(): void
    {
        $container = $this->bootKernel([
            'doctrine' => [
                'dbal' => ['url' => 'duckdb://localhost/' . $this->directory . '/app.duckdb'],
                'orm' => ['mappings' => self::MAPPINGS],
            ],
        ]);

        $entityManager = self::entityManager($container, 'default');
        $connection    = $entityManager->getConnection();

        self::assertInstanceOf(DuckDBPlatform::class, $connection->getDatabasePlatform());
        self::assertSame($this->directory . '/app.duckdb', $connection->getParams()['dbname'] ?? null);

        (new SchemaTool($entityManager))->createSchema([$entityManager->getClassMetadata(Note::class)]);

        $note = new Note('first');
        $entityManager->persist($note);
        $entityManager->flush();

        self::assertSame(ClassMetadata::GENERATOR_TYPE_SEQUENCE, $entityManager->getClassMetadata(Note::class)->generatorType);
        self::assertSame(1, $note->id);
        self::assertSame(1, $connection->fetchOne('SELECT id FROM note WHERE text = ?', ['first']));
        self::assertFileExists($this->directory . '/app.duckdb');
    }

    public function testRegistersTheCustomTypes(): void
    {
        $container = $this->bootKernel([
            'doctrine' => [
                'dbal' => ['url' => 'duckdb://localhost'],
            ],
        ]);

        $types = $container->getParameter('doctrine.dbal.connection_factory.types');

        $expected = [];

        foreach (TypeRegistrar::TYPES as $name => $class) {
            $expected[$name] = ['class' => $class];
        }

        self::assertSame($expected, $types);
    }

    public function testEveryEntityManagerPrefersSequencesWithoutAPhantomDefaultManager(): void
    {
        $container = $this->bootKernel([
            'doctrine' => [
                'dbal' => ['url' => 'duckdb://localhost'],
                'orm' => [
                    'default_entity_manager' => 'main',
                    'entity_managers' => [
                        'main' => ['mappings' => self::MAPPINGS],
                        'reporting' => [
                            'mappings' => self::MAPPINGS,
                            'identity_generation_preferences' => [PostgreSQLPlatform::class => 'IDENTITY'],
                        ],
                    ],
                ],
            ],
        ]);

        self::assertSame(['main', 'reporting'], array_keys(self::registry($container)->getManagerNames()));

        $main      = self::entityManager($container, 'main');
        $reporting = self::entityManager($container, 'reporting');

        self::assertSame(
            [DuckDBPlatform::class => ClassMetadata::GENERATOR_TYPE_SEQUENCE],
            $main->getConfiguration()->getIdentityGenerationPreferences(),
        );

        self::assertSame(
            [
                DuckDBPlatform::class => ClassMetadata::GENERATOR_TYPE_SEQUENCE,
                PostgreSQLPlatform::class => ClassMetadata::GENERATOR_TYPE_IDENTITY,
            ],
            $reporting->getConfiguration()->getIdentityGenerationPreferences(),
        );

        self::assertSame(ClassMetadata::GENERATOR_TYPE_SEQUENCE, $main->getClassMetadata(Note::class)->generatorType);
        self::assertSame(ClassMetadata::GENERATOR_TYPE_SEQUENCE, $reporting->getClassMetadata(Note::class)->generatorType);
    }

    public function testBootsADbalOnlyApplication(): void
    {
        $container = $this->bootKernel([
            'doctrine' => [
                'dbal' => ['url' => 'duckdb://localhost'],
            ],
        ]);

        self::assertSame([], self::registry($container)->getManagerNames());

        $connection = $container->get('database_connection');

        self::assertInstanceOf(Connection::class, $connection);
        self::assertSame(42, $connection->fetchOne('SELECT 42'));
    }

    #[RequiresPhpExtension('pdo_sqlite')]
    public function testLeavesASqliteConnectionsIdStrategyUnchanged(): void
    {
        $container = $this->bootKernel([
            'doctrine' => [
                'dbal' => [
                    'connections' => [
                        'default' => ['url' => 'duckdb://localhost'],
                        'legacy' => ['url' => 'sqlite:///:memory:'],
                    ],
                ],
                'orm' => [
                    'entity_managers' => [
                        'default' => ['connection' => 'default', 'mappings' => self::MAPPINGS],
                        'legacy' => ['connection' => 'legacy', 'mappings' => self::MAPPINGS],
                    ],
                ],
            ],
        ]);

        $duckdb = self::entityManager($container, 'default');
        $sqlite = self::entityManager($container, 'legacy');

        self::assertInstanceOf(SQLitePlatform::class, $sqlite->getConnection()->getDatabasePlatform());

        self::assertSame(ClassMetadata::GENERATOR_TYPE_SEQUENCE, $duckdb->getClassMetadata(Note::class)->generatorType);
        self::assertSame(ClassMetadata::GENERATOR_TYPE_IDENTITY, $sqlite->getClassMetadata(Note::class)->generatorType);
    }

    public function testOmitsForeignKeysOnlyOnTheNamedConnection(): void
    {
        $container = $this->bootKernel([
            'doctrine' => [
                'dbal' => [
                    'connections' => [
                        'default' => ['url' => 'duckdb://localhost'],
                        'analytics' => ['url' => 'duckdb://localhost'],
                    ],
                ],
            ],
            'duckdb' => ['omit_foreign_keys' => ['analytics']],
        ]);

        $default   = self::connection($container, 'default')->getDatabasePlatform();
        $analytics = self::connection($container, 'analytics')->getDatabasePlatform();

        self::assertInstanceOf(DuckDBPlatform::class, $default);
        self::assertInstanceOf(DuckDBPlatform::class, $analytics);

        self::assertFalse($default->omitsForeignKeys());
        self::assertTrue($analytics->omitsForeignKeys());
    }

    /** Every DuckDB connection logs schema notices, such as a dropped collation, to the application's logger. */
    public function testLogsSchemaNoticesOnEveryDuckDBConnection(): void
    {
        $container = $this->bootKernel(
            [
                'doctrine' => [
                    'dbal' => [
                        'default_connection' => 'default',
                        'connections' => [
                            'default' => ['url' => 'duckdb://localhost'],
                            'analytics' => ['url' => 'duckdb://localhost'],
                        ],
                    ],
                ],
                'duckdb' => ['omit_foreign_keys' => ['analytics']],
            ],
            static function (ContainerBuilder $container): void {
                $container->register('logger', RecordingLogger::class)->setPublic(true);
            },
        );

        $logger = $container->get('logger');
        self::assertInstanceOf(RecordingLogger::class, $logger);

        foreach (['default', 'analytics'] as $name) {
            $connection = self::connection($container, $name);
            $connection->executeStatement('CREATE TABLE t (id INTEGER PRIMARY KEY, code VARCHAR COLLATE NOCASE)');

            $schemaManager = $connection->createSchemaManager();
            $current       = $schemaManager->introspectSchema();
            $table         = $current->getTable('t')->edit()
                ->modifyColumnByUnquotedName('code', static function (ColumnEditor $column): void {
                    $column->setTypeName(Types::INTEGER);
                })
                ->create();

            $schemaManager->alterSchema($schemaManager->createComparator()->compareSchemas($current, new Schema([$table])));
        }

        self::assertSame(
            [
                'DuckDB drops the collation NOCASE of column t.code: its new type INTEGER has no collation; only '
                    . 'VARCHAR columns have one.',
                'DuckDB drops the collation NOCASE of column t.code: its new type INTEGER has no collation; only '
                    . 'VARCHAR columns have one.',
            ],
            array_column($logger->records, 'message'),
        );

        $analytics = self::connection($container, 'analytics')->getDatabasePlatform();
        self::assertInstanceOf(DuckDBPlatform::class, $analytics);
        self::assertTrue($analytics->omitsForeignKeys());
    }

    /** R32: every entity manager deletes the subclass rows of a removed JOINED-inheritance entity. */
    public function testEveryEntityManagerDeletesTheSubclassRowsOfARemovedJoinedEntity(): void
    {
        $mappings = [
            'ReferentialAction' => [
                'type' => 'attribute',
                'dir' => __DIR__ . '/../../Orm/ReferentialActionFixture',
                'prefix' => 'MartinJuul\DBAL\DuckDB\Tests\Functional\Orm\ReferentialActionFixture',
                'is_bundle' => false,
            ],
        ];

        $container = $this->bootKernel([
            'doctrine' => [
                'dbal' => [
                    'connections' => [
                        'default' => ['url' => 'duckdb://localhost'],
                        'reporting' => ['url' => 'duckdb://localhost'],
                    ],
                ],
                'orm' => [
                    'entity_managers' => [
                        'default' => ['connection' => 'default', 'mappings' => $mappings],
                        'reporting' => ['connection' => 'reporting', 'mappings' => $mappings],
                    ],
                ],
            ],
        ]);

        foreach (['default', 'reporting'] as $name) {
            $entityManager = self::entityManager($container, $name);
            $connection    = $entityManager->getConnection();

            (new SchemaTool($entityManager))->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

            $roadster = new SportsCar('Roadster', 2, 250);

            $entityManager->persist($roadster);
            $entityManager->flush();
            $entityManager->remove($roadster);
            $entityManager->flush();

            self::assertSame(0, $connection->fetchOne('SELECT count(*) FROM vehicle'), $name);
            self::assertSame(0, $connection->fetchOne('SELECT count(*) FROM car'), $name);
            self::assertSame(0, $connection->fetchOne('SELECT count(*) FROM sports_car'), $name);
        }
    }

    /**
     * @param array<string, array<string, mixed>>    $extensionConfigs
     * @param (Closure(ContainerBuilder): void)|null $configureContainer
     */
    private function bootKernel(array $extensionConfigs, Closure|null $configureContainer = null): ContainerInterface
    {
        $kernel = new TestKernel($this->directory, $extensionConfigs, $configureContainer);
        $kernel->boot();

        $this->kernels[] = $kernel;

        return $kernel->getContainer();
    }

    private static function registry(ContainerInterface $container): ManagerRegistry
    {
        $registry = $container->get('doctrine');

        self::assertInstanceOf(ManagerRegistry::class, $registry);

        return $registry;
    }

    private static function entityManager(ContainerInterface $container, string $name): EntityManagerInterface
    {
        $entityManager = self::registry($container)->getManager($name);

        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        return $entityManager;
    }

    private static function connection(ContainerInterface $container, string $name): Connection
    {
        $connection = self::registry($container)->getConnection($name);

        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }
}
