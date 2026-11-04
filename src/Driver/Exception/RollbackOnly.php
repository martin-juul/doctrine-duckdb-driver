<?php

declare(strict_types=1);

namespace MartinJuul\DBAL\DuckDB\Driver\Exception;

use Doctrine\DBAL\Driver\AbstractException;
use DuckDB\ErrorType;

/**
 * An inner transaction level rolled back. The driver flattens nested
 * transactions and cannot undo the inner level's writes on its own, so the
 * whole transaction can only end in a rollback.
 */
final class RollbackOnly extends AbstractException
{
    public static function statementRejected(): self
    {
        return new self(
            'The transaction is rollback-only: an inner transaction level rolled back, so every statement fails '
                . 'until the outermost level commits or rolls back.',
            null,
            ErrorType::Transaction->value,
        );
    }

    public static function nothingCommitted(): self
    {
        return new self(
            'An inner transaction level rolled back, so the transaction was rolled back and nothing was committed.',
            null,
            ErrorType::Transaction->value,
        );
    }
}
