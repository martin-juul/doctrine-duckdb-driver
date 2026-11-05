<?php

declare(strict_types=1);

namespace MartinJuul\DBAL\DuckDB\Driver\Middleware;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\ServerVersionProvider;
use MartinJuul\DBAL\DuckDB\Platform\DuckDBPlatform;
use Psr\Log\LoggerInterface;

/**
 * Logs, as notices, the schema changes on DuckDB connections that lose
 * something DuckDB cannot carry over, such as a column collation that a new
 * column type cannot have.
 *
 * Like {@see OmitForeignKeys}, it swaps in a platform copy, which keeps the
 * other middleware's settings. It never connects, and other platforms pass
 * through unchanged.
 */
final class LogSchemaNotices implements Middleware
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function wrap(Driver $driver): Driver
    {
        return new class ($driver, $this->logger) extends AbstractDriverMiddleware {
            public function __construct(Driver $driver, private readonly LoggerInterface $logger)
            {
                parent::__construct($driver);
            }

            public function getDatabasePlatform(ServerVersionProvider $versionProvider): AbstractPlatform
            {
                $platform = parent::getDatabasePlatform($versionProvider);

                if (! $platform instanceof DuckDBPlatform) {
                    return $platform;
                }

                return $platform->withLogger($this->logger);
            }
        };
    }
}
