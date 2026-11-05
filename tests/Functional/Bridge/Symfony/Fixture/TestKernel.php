<?php

declare(strict_types=1);

namespace MartinJuul\DBAL\DuckDB\Tests\Functional\Bridge\Symfony\Fixture;

use Closure;
use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use MartinJuul\DBAL\DuckDB\Bridge\Symfony\DuckDBBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\BundleInterface;
use Symfony\Component\HttpKernel\Kernel;

use function bin2hex;
use function random_bytes;

/**
 * A kernel with FrameworkBundle, DoctrineBundle and the DuckDB bundle. Each
 * instance compiles its own container in its own directory, so tests can boot
 * differently configured kernels in one process.
 */
final class TestKernel extends Kernel
{
    /**
     * @param array<string, array<string, mixed>>    $extensionConfigs   configuration keyed by extension alias
     * @param (Closure(ContainerBuilder): void)|null $configureContainer registers or replaces services
     */
    public function __construct(
        private readonly string $directory,
        private readonly array $extensionConfigs,
        private readonly Closure|null $configureContainer = null,
    ) {
        parent::__construct('test' . bin2hex(random_bytes(4)), false);
    }

    /** @return iterable<int, BundleInterface> */
    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new DoctrineBundle();
        yield new DuckDBBundle();
    }

    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        $loader->load(function (ContainerBuilder $container): void {
            $container->loadFromExtension('framework', [
                'secret' => 'test',
                'test' => true,
            ]);

            foreach ($this->extensionConfigs as $extension => $config) {
                $container->loadFromExtension($extension, $config);
            }

            if ($this->configureContainer === null) {
                return;
            }

            ($this->configureContainer)($container);
        });
    }

    public function getProjectDir(): string
    {
        return $this->directory;
    }

    public function getCacheDir(): string
    {
        return $this->directory . '/cache';
    }

    public function getLogDir(): string
    {
        return $this->directory . '/log';
    }
}
