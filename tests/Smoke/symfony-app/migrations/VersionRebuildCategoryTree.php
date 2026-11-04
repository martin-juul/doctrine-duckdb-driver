<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\TableEditor;
use Doctrine\Migrations\AbstractMigration;
use MartinJuul\DBAL\DuckDB\Migrations\RebuildTables;

/**
 * Renames category.name to category.title. The category table references
 * itself, so generated migration SQL cannot rebuild it, and the change goes
 * through the package's migration helper instead.
 *
 * The smoke run copies this file into var/migrations once
 * doctrine:migrations:diff has refused the same change. Its version sorts
 * after the generated, timestamped versions.
 */
final class VersionRebuildCategoryTree extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rename category.name to category.title by rebuilding the self-referencing table';
    }

    public function up(Schema $schema): void
    {
        RebuildTables::queue(
            $this->connection,
            static fn (Schema $current): Schema => $current->edit()
                ->modifyTableByUnquotedName('category', static function (TableEditor $table): void {
                    $table->renameColumnByUnquotedName('name', 'title');
                })
                ->create(),
            $this->addSql(...),
        );
    }
}
