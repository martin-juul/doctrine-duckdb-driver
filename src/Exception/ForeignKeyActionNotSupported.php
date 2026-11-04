<?php

declare(strict_types=1);

namespace MartinJuul\DBAL\DuckDB\Exception;

use Doctrine\DBAL\Platforms\Exception\PlatformException;
use LogicException;

use function sprintf;

/**
 * A foreign key requests a referential action DuckDB 2.0 cannot enforce.
 *
 * Creating the key without the action would silently change what deleting
 * or updating a referenced row does, so schema generation fails instead.
 */
final class ForeignKeyActionNotSupported extends LogicException implements PlatformException
{
    /**
     * @param string $table      The referencing table.
     * @param string $constraint The foreign key's name, or a description of its columns when it has none.
     * @param string $action     The rejected clause, such as "ON DELETE CASCADE".
     */
    public static function new(string $table, string $constraint, string $action): self
    {
        return new self(sprintf(
            'Foreign key "%s" on table "%s" requests %s, but DuckDB 2.0 supports only NO ACTION and RESTRICT. '
                . 'Remove the referential action and delete or update the referencing rows in the application.',
            $constraint,
            $table,
            $action,
        ));
    }
}
