<?php

declare(strict_types=1);

namespace MartinJuul\DBAL\DuckDB\ORM;

use Doctrine\DBAL\Exception;
use Doctrine\ORM\Event\PostRemoveEventArgs;
use Doctrine\ORM\Event\PreRemoveEventArgs;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Utility\PersisterHelper;
use MartinJuul\DBAL\DuckDB\Platform\DuckDBPlatform;
use WeakMap;

use function array_combine;
use function array_map;
use function array_values;

/**
 * Deletes the subclass-table rows of a removed entity mapped with JOINED
 * inheritance, on DuckDB connections.
 *
 * ORM deletes only the root-table row of such an entity and relies on the
 * `ON DELETE CASCADE` foreign keys it declares from every subclass table to
 * the root table. DuckDB 2.0 cannot enforce that action, so the platform
 * leaves those keys out and this listener deletes the rows instead: from the
 * table of the entity's class and of each parent class below the root.
 *
 * The entity's identifier is read before removal, because ORM clears a
 * generated identifier once the root row is deleted. The deletes run on
 * `postRemove`, inside the flush transaction and after the root row's
 * delete; no foreign key ties the subclass tables to the root table or to
 * each other, so the order is safe.
 */
final class JoinedSubclassRowsListener
{
    /**
     * The events the listener handles, ORM's `Events::preRemove` and
     * `Events::postRemove`, spelled out so that registering the listener
     * does not need ORM installed.
     */
    public const array EVENTS = ['preRemove', 'postRemove'];

    /** @var WeakMap<object, array<mixed>> */
    private WeakMap $identifiers;

    public function __construct()
    {
        $this->identifiers = new WeakMap();
    }

    /** @throws Exception */
    public function preRemove(PreRemoveEventArgs $args): void
    {
        $entityManager = $args->getObjectManager();
        $entity        = $args->getObject();

        if (! $entityManager->getConnection()->getDatabasePlatform() instanceof DuckDBPlatform) {
            return;
        }

        if (self::subclassTables($entityManager->getClassMetadata($entity::class)) === []) {
            return;
        }

        $this->identifiers[$entity] = $entityManager->getUnitOfWork()->getEntityIdentifier($entity);
    }

    /** @throws Exception */
    public function postRemove(PostRemoveEventArgs $args): void
    {
        $entity     = $args->getObject();
        $identifier = $this->identifiers[$entity] ?? null;

        if ($identifier === null) {
            return;
        }

        unset($this->identifiers[$entity]);

        $entityManager = $args->getObjectManager();
        $connection    = $entityManager->getConnection();
        $platform      = $connection->getDatabasePlatform();
        $quoteStrategy = $entityManager->getConfiguration()->getQuoteStrategy();

        foreach (self::subclassTables($entityManager->getClassMetadata($entity::class)) as $className) {
            $class = $entityManager->getClassMetadata($className);
            $types = array_map(
                static fn (string $field): string => PersisterHelper::getTypeOfField($field, $class, $entityManager)[0],
                $class->identifier,
            );

            $connection->delete(
                $quoteStrategy->getTableName($class, $platform),
                array_combine($quoteStrategy->getIdentifierColumnNames($class, $platform), array_values($identifier)),
                $types,
            );
        }
    }

    /**
     * The classes below the root whose tables hold rows of an entity of the
     * given class: the class itself and its parent classes, most derived
     * first. Empty unless the class uses JOINED inheritance.
     *
     * @param ClassMetadata<object> $class
     *
     * @return list<class-string>
     */
    private static function subclassTables(ClassMetadata $class): array
    {
        if (! $class->isInheritanceTypeJoined()) {
            return [];
        }

        $classes = [];

        foreach ([$class->name, ...$class->parentClasses] as $className) {
            if ($className === $class->rootEntityName) {
                break;
            }

            $classes[] = $className;
        }

        return $classes;
    }
}
