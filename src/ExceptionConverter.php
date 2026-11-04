<?php

declare(strict_types=1);

namespace MartinJuul\DBAL\DuckDB;

use Doctrine\DBAL\Driver\API\ExceptionConverter as ExceptionConverterInterface;
use Doctrine\DBAL\Driver\Exception;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Query;

final class ExceptionConverter implements ExceptionConverterInterface
{
    public function convert(Exception $exception, Query|null $query): DriverException
    {
        return new DriverException($exception, $query);
    }
}
