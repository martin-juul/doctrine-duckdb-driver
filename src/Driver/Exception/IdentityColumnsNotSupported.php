<?php

declare(strict_types=1);

namespace MartinJuul\DBAL\DuckDB\Driver\Exception;

use Doctrine\DBAL\Driver\AbstractException;

final class IdentityColumnsNotSupported extends AbstractException
{
    public static function new(): self
    {
        return new self(
            'DuckDB has no identity columns and no lastval(), so the last inserted ID is unknown. '
            . 'Read generated IDs with INSERT … RETURNING, or use a sequence.',
        );
    }
}
