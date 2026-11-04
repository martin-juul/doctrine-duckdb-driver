<?php

declare(strict_types=1);

namespace MartinJuul\DBAL\DuckDB\Streaming;

use Doctrine\DBAL\Connection;
use DuckDB\Connection as NativeConnection;
use DuckDB\Exception as DuckDBException;
use LogicException;
use MartinJuul\DBAL\DuckDB\Driver\Exception\DriverError;

/**
 * Reads large results in constant memory through the native connection. It
 * bypasses DBAL middleware. While the rows are open, the connection refuses
 * other statements with ErrorType::ResourceInUse; close() or destroying the
 * rows frees it.
 */
final class StreamingQuery
{
    /**
     * @param list<mixed>|array<string, mixed> $params positional list or named map
     *
     * @throws DriverError
     */
    public static function rows(Connection $connection, string $sql, array $params = []): StreamingRows
    {
        $native = $connection->getNativeConnection();

        if (! $native instanceof NativeConnection) {
            throw new LogicException('Streaming needs a DuckDB connection.');
        }

        try {
            return new StreamingRows($native->prepare($sql)->executeStreaming($params));
        } catch (DuckDBException $e) {
            throw DriverError::fromDuckDB($e);
        }
    }
}
