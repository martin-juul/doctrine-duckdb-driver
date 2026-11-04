<?php

declare(strict_types=1);

namespace MartinJuul\DBAL\DuckDB\Driver\Exception;

use Doctrine\DBAL\Driver\AbstractException;
use DuckDB\ErrorType;
use DuckDB\Exception as DuckDBException;
use Throwable;

/**
 * A DuckDB error, or a PHP error ext-duckdb raised while binding, carried as a
 * DBAL driver exception. The code is ext-duckdb's error category.
 */
final class DriverError extends AbstractException
{
    public static function fromDuckDB(DuckDBException $exception): self
    {
        return new self($exception->getMessage(), null, $exception->getCode(), $exception);
    }

    public static function fromBinding(Throwable $error): self
    {
        return new self($error->getMessage(), null, ErrorType::InvalidInput->value, $error);
    }

    public function getErrorType(): ErrorType|null
    {
        return ErrorType::tryFrom($this->getCode());
    }
}
