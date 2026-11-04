<?php

declare(strict_types=1);

namespace MartinJuul\DBAL\DuckDB\Tests\Functional\Migrations;

use Closure;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Configuration\Configuration;
use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\Configuration\Migration\ExistingConfiguration;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Metadata\AvailableMigration;
use Doctrine\Migrations\Metadata\AvailableMigrationsSet;
use Doctrine\Migrations\MigrationsRepository;
use Doctrine\Migrations\MigratorConfiguration;
use Doctrine\Migrations\Query\Query;
use Doctrine\Migrations\Version\Version;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

use function array_map;
use function array_values;
use function count;
use function is_string;
use function sprintf;

/**
 * Runs migrations through Doctrine Migrations' own migrator, as the migrate
 * command does, with migrations given as closures instead of class files.
 */
final class MigrationRunner
{
    /** @var list<AvailableMigration> */
    private array $migrations = [];

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * Adds a migration whose up() calls the closure with the connection and
     * the migration's addSql().
     *
     * @param Closure(Connection, Closure(string): void): void $up
     */
    public function add(Closure $up): self
    {
        $migration = new class ($this->connection, new NullLogger(), $up) extends AbstractMigration {
            /** @param Closure(Connection, Closure(string): void): void $up */
            public function __construct(
                Connection $connection,
                LoggerInterface $logger,
                private readonly Closure $up,
            ) {
                parent::__construct($connection, $logger);
            }

            public function up(Schema $schema): void
            {
                ($this->up)($this->connection, function (string $sql): void {
                    $this->addSql($sql);
                });
            }
        };

        $this->migrations[] = new AvailableMigration(
            new Version(sprintf('Version%03d', count($this->migrations) + 1)),
            $migration,
        );

        return $this;
    }

    /**
     * Migrates to the latest version and returns the SQL of each migration.
     *
     * @return array<string, list<string>>
     */
    public function migrate(bool $allOrNothing = false, bool $dryRun = false): array
    {
        $dependencyFactory = DependencyFactory::fromConnection(
            new ExistingConfiguration(new Configuration()),
            new ExistingConnection($this->connection),
        );

        $repository = new class (new AvailableMigrationsSet($this->migrations)) implements MigrationsRepository {
            public function __construct(private readonly AvailableMigrationsSet $migrations)
            {
            }

            public function hasMigration(string $version): bool
            {
                return $this->migrations->hasMigration(new Version($version));
            }

            public function getMigration(Version $version): AvailableMigration
            {
                return $this->migrations->getMigration($version);
            }

            public function getMigrations(): AvailableMigrationsSet
            {
                return $this->migrations;
            }
        };

        $dependencyFactory->setService(MigrationsRepository::class, $repository);
        $dependencyFactory->getMetadataStorage()->ensureInitialized();

        $plan = $dependencyFactory->getMigrationPlanCalculator()->getPlanUntilVersion(
            $dependencyFactory->getVersionAliasResolver()->resolveVersionAlias('latest'),
        );

        $sql = $dependencyFactory->getMigrator()->migrate(
            $plan,
            (new MigratorConfiguration())->setAllOrNothing($allOrNothing)->setDryRun($dryRun),
        );

        return array_map(
            static fn (array $queries): array => array_values(array_map(
                static fn (Query $query): string => $query->getStatement(),
                $queries,
            )),
            $sql,
        );
    }

    /**
     * The versions Migrations recorded as executed.
     *
     * @return list<string>
     */
    public function executedVersions(): array
    {
        $versions = [];

        $rows = $this->connection->fetchFirstColumn('SELECT version FROM doctrine_migration_versions ORDER BY version');

        foreach ($rows as $version) {
            if (! is_string($version)) {
                continue;
            }

            $versions[] = $version;
        }

        return $versions;
    }
}
