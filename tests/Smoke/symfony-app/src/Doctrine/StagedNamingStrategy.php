<?php

declare(strict_types=1);

namespace App\Doctrine;

use App\Entity\Category;
use App\Entity\User;
use Doctrine\ORM\Mapping\NamingStrategy;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;

use const CASE_LOWER;

/**
 * Lets the smoke run change the mapping between migrations without editing
 * the entities: SMOKE_SCHEMA_STAGE=2 renames User::$email's column, and
 * SMOKE_SCHEMA_STAGE=3 also renames Category::$name's column. Every other
 * name follows the underscore strategy.
 */
final class StagedNamingStrategy implements NamingStrategy
{
    private readonly UnderscoreNamingStrategy $inner;

    public function __construct(private readonly int $stage)
    {
        $this->inner = new UnderscoreNamingStrategy(CASE_LOWER);
    }

    public function classToTableName(string $className): string
    {
        return $this->inner->classToTableName($className);
    }

    public function propertyToColumnName(string $propertyName, string $className): string
    {
        if ($this->stage >= 2 && $className === User::class && $propertyName === 'email') {
            return 'email_address';
        }

        if ($this->stage >= 3 && $className === Category::class && $propertyName === 'name') {
            return 'title';
        }

        return $this->inner->propertyToColumnName($propertyName, $className);
    }

    public function embeddedFieldToColumnName(
        string $propertyName,
        string $embeddedColumnName,
        string $className,
        string $embeddedClassName,
    ): string {
        return $this->inner->embeddedFieldToColumnName($propertyName, $embeddedColumnName, $className, $embeddedClassName);
    }

    public function referenceColumnName(): string
    {
        return $this->inner->referenceColumnName();
    }

    public function joinColumnName(string $propertyName, string $className): string
    {
        return $this->inner->joinColumnName($propertyName, $className);
    }

    public function joinTableName(string $sourceEntity, string $targetEntity, string $propertyName): string
    {
        return $this->inner->joinTableName($sourceEntity, $targetEntity, $propertyName);
    }

    public function joinKeyColumnName(string $entityName, string|null $referencedColumnName): string
    {
        return $this->inner->joinKeyColumnName($entityName, $referencedColumnName);
    }
}
