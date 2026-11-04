<?php

declare(strict_types=1);

namespace MartinJuul\DBAL\DuckDB\Driver\Middleware;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\ServerVersionProvider;
use MartinJuul\DBAL\DuckDB\Platform\DuckDBPlatform;

/**
 * Omits foreign-key constraints from the DDL generated for DuckDB connections.
 *
 * DBAL creates drivers without arguments, so no connection parameter reaches
 * the platform; this middleware swaps in a platform copy instead. It never
 * connects, and other platforms pass through unchanged.
 */
final class OmitForeignKeys implements Middleware
{
    public function wrap(Driver $driver): Driver
    {
        return new class ($driver) extends AbstractDriverMiddleware {
            public function getDatabasePlatform(ServerVersionProvider $versionProvider): AbstractPlatform
            {
                $platform = parent::getDatabasePlatform($versionProvider);

                if (! $platform instanceof DuckDBPlatform) {
                    return $platform;
                }

                return $platform->withoutForeignKeys();
            }
        };
    }
}
