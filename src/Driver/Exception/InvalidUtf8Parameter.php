<?php

declare(strict_types=1);

namespace MartinJuul\DBAL\DuckDB\Driver\Exception;

use Doctrine\DBAL\Driver\AbstractException;
use DuckDB\ErrorType;

use function sprintf;

final class InvalidUtf8Parameter extends AbstractException
{
    public static function forParameter(int|string $param): self
    {
        return new self(
            sprintf(
                'Parameter %s is not valid UTF-8. Bind binary data with ParameterType::BINARY or ParameterType::LARGE_OBJECT.',
                $param,
            ),
            null,
            ErrorType::Conversion->value,
        );
    }
}
