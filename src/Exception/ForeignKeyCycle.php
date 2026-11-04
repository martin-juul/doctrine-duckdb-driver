<?php

declare(strict_types=1);

namespace MartinJuul\DBAL\DuckDB\Exception;

use Doctrine\DBAL\Platforms\Exception\PlatformException;
use LogicException;

use function implode;
use function sprintf;

/**
 * Foreign keys between the tables of a schema form a cycle.
 *
 * DuckDB 2.0 creates a foreign key only together with its table, and the
 * referenced table must already exist, so such a schema cannot be created.
 */
final class ForeignKeyCycle extends LogicException implements PlatformException
{
    /** @param non-empty-list<string> $tables The tables in the cycle, in reference order. */
    public static function new(array $tables): self
    {
        return new self(sprintf(
            'The foreign keys between tables "%s" form a cycle. DuckDB 2.0 creates a foreign key only together '
                . 'with its table, and only when the referenced table already exists, so it cannot create this '
                . 'schema. Remove one of the foreign keys, or omit foreign keys with the OmitForeignKeys middleware.',
            implode('", "', $tables),
        ));
    }
}
