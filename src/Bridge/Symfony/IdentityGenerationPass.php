<?php

declare(strict_types=1);

namespace MartinJuul\DBAL\DuckDB\Bridge\Symfony;

use Doctrine\ORM\Mapping\ClassMetadata;
use MartinJuul\DBAL\DuckDB\Platform\DuckDBPlatform;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

use function array_keys;
use function array_merge;
use function is_array;
use function sprintf;

/**
 * Adds ORM's sequence preference for the DuckDB platform to every entity
 * manager's configuration, keeping the preferences configured there.
 *
 * It runs as a compiler pass because prepending `doctrine.orm` configuration
 * would create a `default` entity manager in applications without one.
 */
final class IdentityGenerationPass implements CompilerPassInterface
{
    private const string SETTER = 'setIdentityGenerationPreferences';

    public function process(ContainerBuilder $container): void
    {
        if (! $container->hasParameter('doctrine.entity_managers')) {
            return;
        }

        $entityManagers = $container->getParameter('doctrine.entity_managers');

        if (! is_array($entityManagers)) {
            return;
        }

        foreach (array_keys($entityManagers) as $name) {
            $id = sprintf('doctrine.orm.%s_configuration', $name);

            if (! $container->hasDefinition($id)) {
                continue;
            }

            $definition  = $container->getDefinition($id);
            $preferences = self::configuredPreferences($definition);

            $definition->removeMethodCall(self::SETTER);
            $definition->addMethodCall(self::SETTER, [
                array_merge([DuckDBPlatform::class => ClassMetadata::GENERATOR_TYPE_SEQUENCE], $preferences),
            ]);
        }
    }

    /**
     * The preferences DoctrineBundle passes to the configuration's setter.
     *
     * @return array<mixed>
     */
    private static function configuredPreferences(Definition $definition): array
    {
        $preferences = [];

        foreach ($definition->getMethodCalls() as $call) {
            if (! is_array($call) || ($call[0] ?? null) !== self::SETTER) {
                continue;
            }

            $arguments = $call[1] ?? null;

            if (! is_array($arguments) || ! is_array($arguments[0] ?? null)) {
                continue;
            }

            $preferences = $arguments[0];
        }

        return $preferences;
    }
}
