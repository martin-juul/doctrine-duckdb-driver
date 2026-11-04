<?php

declare(strict_types=1);

namespace MartinJuul\DBAL\DuckDB;

use Doctrine\DBAL\Driver as DriverInterface;
use Doctrine\DBAL\Driver\API\ExceptionConverter as ExceptionConverterInterface;
use Doctrine\DBAL\ServerVersionProvider;
use DuckDB\Database;
use DuckDB\Exception as DuckDBException;
use MartinJuul\DBAL\DuckDB\Driver\Connection;
use MartinJuul\DBAL\DuckDB\Driver\Exception\DriverError;
use MartinJuul\DBAL\DuckDB\Platform\DuckDBPlatform;
use SensitiveParameter;

use function date_default_timezone_get;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_string;
use function str_replace;

/**
 * Doctrine DBAL driver for DuckDB through the native ext-duckdb extension.
 *
 * Connection parameters: `dbname` or `path` names the database file (`dbname`
 * wins), `memory` or no file parameter selects an in-memory database, and
 * `driverOptions` passes DuckDB configuration options such as
 * `['access_mode' => 'read_only', 'threads' => 4]`.
 */
final class Driver implements DriverInterface
{
    /** @param array<string, mixed> $params */
    public function connect(
        #[SensitiveParameter]
        array $params,
    ): Connection {
        $path   = self::resolvePath($params);
        $config = self::resolveConfig($params);

        try {
            $database   = new Database($path, $config);
            $connection = $database->connect();
            $connection->query('SET SESSION TimeZone = ' . self::quoteLiteral(date_default_timezone_get()));
        } catch (DuckDBException $e) {
            throw DriverError::fromDuckDB($e);
        }

        return new Connection($database, $connection);
    }

    /**
     * Never connects: DoctrineBundle and ORM metadata warm-up ask for the
     * platform while another process may hold the database file.
     */
    public function getDatabasePlatform(ServerVersionProvider $versionProvider): DuckDBPlatform
    {
        return new DuckDBPlatform();
    }

    public function getExceptionConverter(): ExceptionConverterInterface
    {
        return new ExceptionConverter();
    }

    /** @param array<string, mixed> $params */
    private static function resolvePath(array $params): string
    {
        if (! empty($params['memory'])) {
            return ':memory:';
        }

        foreach (['dbname', 'path'] as $key) {
            if (isset($params[$key]) && is_string($params[$key]) && $params[$key] !== '') {
                return $params[$key];
            }
        }

        return ':memory:';
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, string|int|float|bool>
     */
    private static function resolveConfig(array $params): array
    {
        $options = $params['driverOptions'] ?? [];

        if (! is_array($options)) {
            return [];
        }

        $config = [];

        foreach ($options as $name => $value) {
            if (! is_string($name)) {
                continue;
            }

            if (! is_string($value) && ! is_int($value) && ! is_float($value) && ! is_bool($value)) {
                continue;
            }

            $config[$name] = $value;
        }

        return $config;
    }

    private static function quoteLiteral(string $value): string
    {
        return "'" . str_replace("'", "''", $value) . "'";
    }
}
