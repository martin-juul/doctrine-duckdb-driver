<?php

declare(strict_types=1);

namespace MartinJuul\DBAL\DuckDB\Tests\Functional;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use MartinJuul\DBAL\DuckDB\Driver;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\TestCase;

use function bin2hex;
use function file_exists;
use function random_bytes;
use function sys_get_temp_dir;
use function unlink;

abstract class FunctionalTestCase extends TestCase
{
    /** @var list<string> */
    private array $temporaryFiles = [];

    /** @param array<string, mixed> $params */
    protected static function connect(array $params = []): Connection
    {
        if (! isset($params['dbname']) && ! isset($params['path'])) {
            $params['memory'] = true;
        }

        // @phpstan-ignore argument.type (tests pass DuckDB-specific parameters DBAL does not declare)
        return DriverManager::getConnection(['driverClass' => Driver::class] + $params);
    }

    /** A path for a new database file, deleted with its write-ahead log after the test. */
    protected function temporaryDatabasePath(): string
    {
        $path = sys_get_temp_dir() . '/dbal-duckdb-' . bin2hex(random_bytes(6)) . '.duckdb';

        $this->temporaryFiles[] = $path;
        $this->temporaryFiles[] = $path . '.wal';

        return $path;
    }

    #[After]
    protected function removeTemporaryFiles(): void
    {
        foreach ($this->temporaryFiles as $file) {
            if (! file_exists($file)) {
                continue;
            }

            unlink($file);
        }

        $this->temporaryFiles = [];
    }
}
