<?php

declare(strict_types=1);

namespace MartinJuul\DBAL\DuckDB\Driver\Exception;

use Doctrine\DBAL\Driver\AbstractException;
use DuckDB\ErrorType;

use function sprintf;

/** A transaction statement the driver handles itself arrived where it cannot run. */
final class TransactionStatementRejected extends AbstractException
{
    public static function literalControl(string $sql): self
    {
        return new self(
            sprintf(
                'The DuckDB driver does not run "%s". Control transactions with the connection\'s '
                    . 'beginTransaction(), commit() and rollBack() methods.',
                $sql,
            ),
            null,
            ErrorType::Transaction->value,
        );
    }

    public static function savepointOutsideTransaction(string $sql): self
    {
        return new self(
            sprintf(
                'Savepoint statements only run inside a transaction, and no transaction is active for "%s".',
                $sql,
            ),
            null,
            ErrorType::Transaction->value,
        );
    }

    public static function rebuildEndOutsideRebuild(): self
    {
        return new self(
            'The table rebuild end marker arrived with no transaction open: an earlier error rolled the '
                . 'rebuild back, or its begin marker was never sent.',
            null,
            ErrorType::Transaction->value,
        );
    }
}
