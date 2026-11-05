<?php

declare(strict_types=1);

namespace MartinJuul\DBAL\DuckDB\Tests\Functional\Fixtures;

use Psr\Log\AbstractLogger;
use Stringable;

use function is_scalar;
use function is_string;
use function strtr;

/** Keeps every log record, with its placeholders replaced by the context values. */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array<mixed>}> */
    public array $records = [];

    /** @param array<mixed> $context */
    public function log(mixed $level, string|Stringable $message, array $context = []): void
    {
        $replacements = [];

        foreach ($context as $key => $value) {
            if (! is_scalar($value)) {
                continue;
            }

            $replacements['{' . $key . '}'] = (string) $value;
        }

        $this->records[] = [
            'level' => is_string($level) ? $level : '',
            'message' => strtr((string) $message, $replacements),
            'context' => $context,
        ];
    }
}
