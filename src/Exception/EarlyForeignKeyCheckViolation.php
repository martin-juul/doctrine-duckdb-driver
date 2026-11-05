<?php

declare(strict_types=1);

namespace MartinJuul\DBAL\DuckDB\Exception;

use Doctrine\DBAL\Driver\Exception as DriverExceptionInterface;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Query;

/**
 * A foreign-key violation reported with DuckDB's "foreign key limitations"
 * hint. DuckDB raises the same error for a row that is still referenced and
 * for valid statement sequences that its early foreign-key check rejects, so
 * the message explains both.
 */
final class EarlyForeignKeyCheckViolation extends ForeignKeyConstraintViolationException
{
    private const string EXPLANATION = 'The row may still be referenced, or DuckDB\'s early foreign-key check '
        . 'may have rejected a valid sequence of statements. DuckDB checks foreign keys when each statement '
        . 'runs, does not see child rows deleted earlier in the same transaction until it commits, and runs '
        . 'an UPDATE that sets an indexed column, such as a key, as a delete and an insert, so such an update '
        . 'of a referenced row fails even when the value does not change. See "Over-eager constraint checking '
        . 'in foreign keys" in DuckDB\'s documentation on indexes.';

    public function __construct(DriverExceptionInterface $driverException, Query|null $query)
    {
        parent::__construct($driverException, $query);

        $this->message .= "\n" . self::EXPLANATION;
    }
}
