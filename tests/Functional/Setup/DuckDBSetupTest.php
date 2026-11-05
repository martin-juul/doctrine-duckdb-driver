<?php

declare(strict_types=1);

namespace MartinJuul\DBAL\DuckDB\Tests\Functional\Setup;

use Doctrine\DBAL\Configuration as DBALConfiguration;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Logging\Middleware as LoggingMiddleware;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\Configuration as ORMConfiguration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use MartinJuul\DBAL\DuckDB\Driver;
use MartinJuul\DBAL\DuckDB\Driver\Exception\IdentityColumnsNotSupported;
use MartinJuul\DBAL\DuckDB\Driver\Middleware\OmitForeignKeys;
use MartinJuul\DBAL\DuckDB\Platform\DuckDBPlatform;
use MartinJuul\DBAL\DuckDB\Setup\DuckDBSetup;
use MartinJuul\DBAL\DuckDB\Tests\Functional\Bridge\Symfony\Fixture\Entity\Note;
use MartinJuul\DBAL\DuckDB\Tests\Functional\FunctionalTestCase;
use MartinJuul\DBAL\DuckDB\Types\TypeRegistrar;
use PHPUnit\Framework\Attributes\After;
use Psr\Log\NullLogger;
use Symfony\Component\Filesystem\Filesystem;
use Throwable;

use function array_keys;
use function bin2hex;
use function chdir;
use function getcwd;
use function random_bytes;
use function sys_get_temp_dir;

final class DuckDBSetupTest extends FunctionalTestCase
{
    private string|null $directory = null;

    private string|null $previousWorkingDirectory = null;

    #[After]
    protected function restoreWorkingDirectoryAndRemoveDirectory(): void
    {
        if ($this->previousWorkingDirectory !== null) {
            chdir($this->previousWorkingDirectory);
        }

        if ($this->directory === null) {
            return;
        }

        (new Filesystem())->remove($this->directory);
    }

    public function testConfigureOrmPrefersSequencesOnDuckDBAndKeepsOtherPreferences(): void
    {
        $configuration = self::ormConfiguration();
        $configuration->setIdentityGenerationPreferences([PostgreSQLPlatform::class => ClassMetadata::GENERATOR_TYPE_IDENTITY]);

        DuckDBSetup::configureOrm($configuration);

        self::assertSame(
            [
                DuckDBPlatform::class => ClassMetadata::GENERATOR_TYPE_SEQUENCE,
                PostgreSQLPlatform::class => ClassMetadata::GENERATOR_TYPE_IDENTITY,
            ],
            $configuration->getIdentityGenerationPreferences(),
        );

        $entityManager = new EntityManager(self::connect(), $configuration);

        self::assertSame(ClassMetadata::GENERATOR_TYPE_SEQUENCE, $entityManager->getClassMetadata(Note::class)->generatorType);

        (new SchemaTool($entityManager))->createSchema([$entityManager->getClassMetadata(Note::class)]);

        $note = new Note('first');
        $entityManager->persist($note);
        $entityManager->flush();

        self::assertSame(1, $note->id);
    }

    public function testConfigureOrmRegistersTheCustomTypes(): void
    {
        DuckDBSetup::configureOrm(self::ormConfiguration());

        foreach (TypeRegistrar::TYPES as $name => $class) {
            self::assertInstanceOf($class, Type::getType($name));
        }
    }

    public function testDsnParserOpensARelativePath(): void
    {
        $this->directory                = sys_get_temp_dir() . '/doctrine-duckdb-driver-setup-' . bin2hex(random_bytes(6));
        $this->previousWorkingDirectory = (string) getcwd();

        (new Filesystem())->mkdir($this->directory . '/var');
        chdir($this->directory);

        $params = DuckDBSetup::createDsnParser()->parse('duckdb://localhost/var/app.duckdb');

        self::assertSame(['host' => 'localhost', 'driverClass' => Driver::class, 'dbname' => 'var/app.duckdb'], $params);

        $connection = DriverManager::getConnection($params);
        $connection->executeStatement('CREATE TABLE t (i INTEGER)');
        $connection->close();

        self::assertFileExists($this->directory . '/var/app.duckdb');
    }

    public function testDsnParserKeepsAnAbsolutePath(): void
    {
        $params = DuckDBSetup::createDsnParser()->parse('duckdb://localhost//srv/data/app.duckdb');

        self::assertSame('/srv/data/app.duckdb', $params['dbname'] ?? null);
    }

    public function testOmitForeignKeysAppendsTheMiddleware(): void
    {
        $logging       = new LoggingMiddleware(new NullLogger());
        $configuration = new DBALConfiguration();
        $configuration->setMiddlewares([$logging]);

        DuckDBSetup::omitForeignKeys($configuration);

        $middlewares = $configuration->getMiddlewares();

        self::assertSame([0, 1], array_keys($middlewares));
        self::assertSame($logging, $middlewares[0]);
        self::assertInstanceOf(OmitForeignKeys::class, $middlewares[1]);

        $platform = DriverManager::getConnection(['driverClass' => Driver::class, 'memory' => true], $configuration)
            ->getDatabasePlatform();

        self::assertInstanceOf(DuckDBPlatform::class, $platform);
        self::assertTrue($platform->omitsForeignKeys());
    }

    /** Documents why the setup is needed: ORM resolves AUTO to IDENTITY, and DuckDB has no identity columns. */
    public function testWithoutTheSetupPersistingADefaultIdEntityFails(): void
    {
        $entityManager = new EntityManager(self::connect(), self::ormConfiguration());

        self::assertSame(ClassMetadata::GENERATOR_TYPE_IDENTITY, $entityManager->getClassMetadata(Note::class)->generatorType);

        (new SchemaTool($entityManager))->createSchema([$entityManager->getClassMetadata(Note::class)]);

        $entityManager->persist(new Note('first'));

        $failure = null;

        try {
            $entityManager->flush();
        } catch (Throwable $failure) {
        }

        self::assertInstanceOf(DriverException::class, $failure);
        self::assertInstanceOf(IdentityColumnsNotSupported::class, $failure->getPrevious());
        self::assertStringContainsString('RETURNING', $failure->getMessage());
    }

    private static function ormConfiguration(): ORMConfiguration
    {
        $configuration = ORMSetup::createAttributeMetadataConfig([__DIR__ . '/../Bridge/Symfony/Fixture/Entity'], true);
        $configuration->enableNativeLazyObjects(true);

        return $configuration;
    }
}
