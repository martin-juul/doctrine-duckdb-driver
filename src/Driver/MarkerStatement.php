<?php

declare(strict_types=1);

namespace MartinJuul\DBAL\DuckDB\Driver;

use Doctrine\DBAL\Driver\Statement as StatementInterface;
use Doctrine\DBAL\ParameterType;

/** A prepared savepoint, rebuild-marker or transaction-control statement; it acts when executed. */
final class MarkerStatement implements StatementInterface
{
    public function __construct(
        private readonly TransactionState $transaction,
        private readonly StatementKind $kind,
        private readonly string $sql,
    ) {
    }

    public function bindValue(int|string $param, mixed $value, ParameterType $type): void
    {
    }

    public function execute(): EmptyResult
    {
        $this->transaction->intercept($this->kind, $this->sql);

        return new EmptyResult();
    }
}
