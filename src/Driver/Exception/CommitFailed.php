<?php

declare(strict_types=1);

namespace MartinJuul\DBAL\DuckDB\Driver\Exception;

use Doctrine\DBAL\Driver\AbstractException;
use DuckDB\Exception as DuckDBException;

/**
 * DuckDB failed to commit and the driver has ended the transaction, so
 * nothing was committed. DuckDB's message is kept, because exception
 * conversion recognises a commit-time uniqueness conflict by it.
 */
final class CommitFailed extends AbstractException
{
    public static function fromDuckDB(DuckDBException $exception): self
    {
        return new self(
            'The commit failed and the transaction was rolled back: ' . $exception->getMessage(),
            null,
            $exception->getCode(),
            $exception,
        );
    }
}
