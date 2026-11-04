<?php

declare(strict_types=1);

namespace App\Command;

use RuntimeException;

use function json_encode;
use function sprintf;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * A smoke check found a value it did not expect.
 */
final class SmokeFailed extends RuntimeException
{
    public static function unlessSame(mixed $expected, mixed $actual, string $what): void
    {
        if ($expected === $actual) {
            return;
        }

        throw new self(sprintf(
            '%s: expected %s, got %s.',
            $what,
            json_encode($expected, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            json_encode($actual, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ));
    }
}
