<?php

declare(strict_types=1);

namespace MartinJuul\DBAL\DuckDB\Schema;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Schema\DefaultExpression;

/**
 * A column default that is an SQL expression, such as `concat('a', 'b')`,
 * which renders as written instead of as a string literal.
 *
 * Introspection reports the expression defaults that no DBAL value or
 * default expression represents with it.
 */
final readonly class RawDefaultExpression implements DefaultExpression
{
    public function __construct(public string $sql)
    {
    }

    public function toSQL(AbstractPlatform $platform): string
    {
        return $this->sql;
    }
}
