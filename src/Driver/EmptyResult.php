<?php

declare(strict_types=1);

namespace MartinJuul\DBAL\DuckDB\Driver;

use Doctrine\DBAL\Driver\Result as ResultInterface;
use OutOfBoundsException;

/** The result of a statement the driver handled without DuckDB: no columns, no rows. */
final class EmptyResult implements ResultInterface
{
    public function fetchNumeric(): false
    {
        return false;
    }

    public function fetchAssociative(): false
    {
        return false;
    }

    public function fetchOne(): false
    {
        return false;
    }

    /** @return list<list<mixed>> */
    public function fetchAllNumeric(): array
    {
        return [];
    }

    /** @return list<array<string, mixed>> */
    public function fetchAllAssociative(): array
    {
        return [];
    }

    /** @return list<mixed> */
    public function fetchFirstColumn(): array
    {
        return [];
    }

    public function rowCount(): int
    {
        return 0;
    }

    public function columnCount(): int
    {
        return 0;
    }

    public function getColumnName(int $index): never
    {
        throw new OutOfBoundsException('Column index ' . $index . ' is out of range.');
    }

    public function free(): void
    {
    }
}
