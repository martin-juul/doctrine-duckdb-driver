<?php

declare(strict_types=1);

namespace MartinJuul\DBAL\DuckDB\Streaming;

use Closure;
use DuckDB\Exception as DuckDBException;
use DuckDB\FetchMode;
use DuckDB\Result as NativeResult;
use Iterator;
use MartinJuul\DBAL\DuckDB\Driver\Exception\DriverError;
use MartinJuul\DBAL\DuckDB\Driver\ValueNormalizer;

/**
 * Rows of a streaming result, keyed by column name. The native result is released
 * by close(), at the end of iteration and when the iterator is destroyed.
 *
 * @implements Iterator<int, array<string, mixed>>
 */
final class StreamingRows implements Iterator
{
    private NativeResult|null $result;

    /** @var list<string> */
    private array $names = [];

    /** @var list<Closure(mixed): mixed> */
    private array $normalizers = [];

    /** @var array<string, mixed>|null */
    private array|null $current = null;

    private int $position = -1;

    public function __construct(NativeResult $result)
    {
        $this->result = $result;

        for ($i = 0, $count = $result->columnCount(); $i < $count; $i++) {
            $this->names[]       = $result->columnName($i);
            $this->normalizers[] = ValueNormalizer::forColumnType($result->columnType($i));
        }
    }

    /** Releases the stream so the connection accepts statements again. */
    public function close(): void
    {
        $this->result  = null;
        $this->current = null;
    }

    /** @return array<string, mixed> */
    public function current(): array
    {
        $this->start();

        return $this->current ?? [];
    }

    public function key(): int
    {
        $this->start();

        return $this->position;
    }

    public function next(): void
    {
        $this->start();
        $this->advance();
    }

    public function rewind(): void
    {
        $this->start();
    }

    public function valid(): bool
    {
        $this->start();

        return $this->current !== null;
    }

    private function start(): void
    {
        if ($this->position >= 0 || $this->result === null) {
            return;
        }

        $this->advance();
    }

    private function advance(): void
    {
        if ($this->result === null) {
            return;
        }

        try {
            /** @var list<mixed>|null $row */
            $row = $this->result->fetchRow(FetchMode::Num);
        } catch (DuckDBException $e) {
            $this->close();

            throw DriverError::fromDuckDB($e);
        }

        if ($row === null) {
            $this->close();

            return;
        }

        $assoc = [];

        foreach ($this->names as $index => $name) {
            $assoc[$name] = $this->normalizers[$index]($row[$index]);
        }

        $this->current = $assoc;
        $this->position++;
    }
}
