<?php

declare(strict_types=1);

namespace MartinJuul\DBAL\DuckDB\Migrations;

use Closure;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Schema\ComparatorConfig;
use Doctrine\DBAL\Schema\Schema;
use MartinJuul\DBAL\DuckDB\Platform\DuckDBPlatform;
use MartinJuul\DBAL\DuckDB\Schema\TableRebuilder;
use UnexpectedValueException;

use function get_debug_type;
use function sprintf;

/**
 * Applies, from a Doctrine Migrations migration, a schema change that
 * rebuilds a self-referencing table.
 *
 * DuckDB checks foreign keys against the state before each statement, so a
 * self-referencing table is copied one tree level per statement, and the
 * number of levels depends on its data. Generated migration SQL therefore
 * refuses such a rebuild. Call this helper from the migration's up() instead:
 *
 *     public function up(Schema $schema): void
 *     {
 *         RebuildTables::queue(
 *             $this->connection,
 *             static fn (Schema $current): Schema => ..., // the target schema
 *             $this->addSql(...),
 *         );
 *     }
 *
 * It compares the database's current schema with the target, counts the
 * tree's levels with a read-only query, and queues the statements through
 * the migration's addSql(): staging copies, drops, creates, one copy
 * statement per level, and a check that fails when rows are left over,
 * because their parents are missing or form a cycle. It executes nothing
 * itself, so `--dry-run`, for which Migrations still calls up(), only prints
 * the statements. They run in order inside the migration's transaction, or
 * in a transaction the driver opens when the migration is not transactional.
 *
 * Do not also change the tables through the migration's $schema argument:
 * Migrations would generate SQL for those changes too.
 */
final class RebuildTables
{
    /**
     * @param Closure(Schema): Schema $target Returns the target schema from the database's current schema.
     * @param callable(string): mixed $addSql The migration's addSql(), as `$this->addSql(...)`.
     *
     * @throws Exception
     */
    public static function queue(Connection $connection, Closure $target, callable $addSql): void
    {
        $platform = $connection->getDatabasePlatform();

        if (! $platform instanceof DuckDBPlatform) {
            throw new UnexpectedValueException(sprintf(
                '%s works only on DuckDB connections, not on %s.',
                self::class,
                get_debug_type($platform),
            ));
        }

        $schemaManager = $platform->createSchemaManager($connection);
        $current       = $schemaManager->introspectSchema();
        $comparator    = $schemaManager->createComparator(
            (new ComparatorConfig())->withReportModifiedIndexes(false),
        );

        $diff      = $comparator->compareSchemas($current, $target(clone $current));
        $rebuilder = new TableRebuilder($platform);

        foreach ($rebuilder->withLevels($connection, $rebuilder->schemaDiffSteps($diff)) as $sql) {
            $addSql($sql);
        }
    }
}
