<?php

declare(strict_types=1);

namespace MartinJuul\DBAL\DuckDB\Driver;

use Closure;
use Doctrine\DBAL\Driver\Result as ResultInterface;
use Doctrine\DBAL\Exception\InvalidColumnIndex;
use DuckDB\Exception as DuckDBException;
use DuckDB\FetchMode;
use DuckDB\Result as NativeResult;
use MartinJuul\DBAL\DuckDB\Driver\Exception\DriverError;

use function count;
use function in_array;

/** A fully buffered ext-duckdb result, with values normalized for DBAL types. */
final class Result implements ResultInterface
{
    private const array DML = ['INSERT', 'UPDATE', 'DELETE'];

    private NativeResult|null $result;

    /** @var list<string>|null */
    private array|null $names = null;

    /** @var list<Closure(mixed): mixed>|null */
    private array|null $normalizers = null;

    public function __construct(NativeResult $result)
    {
        $this->result = $result;
    }

    /** @return list<mixed>|false */
    public function fetchNumeric(): array|false
    {
        if ($this->result === null) {
            return false;
        }

        try {
            /** @var list<mixed>|null $row */
            $row = $this->result->fetchRow(FetchMode::Num);
        } catch (DuckDBException $e) {
            throw DriverError::fromDuckDB($e);
        }

        if ($row === null) {
            return false;
        }

        $normalizers = $this->normalizers();

        foreach ($row as $index => $value) {
            $row[$index] = $normalizers[$index]($value);
        }

        return $row;
    }

    /** @return array<string, mixed>|false */
    public function fetchAssociative(): array|false
    {
        $row = $this->fetchNumeric();

        if ($row === false) {
            return false;
        }

        $assoc = [];

        foreach ($this->names() as $index => $name) {
            $assoc[$name] = $row[$index];
        }

        return $assoc;
    }

    public function fetchOne(): mixed
    {
        $row = $this->fetchNumeric();

        return $row === false ? false : $row[0];
    }

    /** @return list<list<mixed>> */
    public function fetchAllNumeric(): array
    {
        $rows = [];

        while (($row = $this->fetchNumeric()) !== false) {
            $rows[] = $row;
        }

        return $rows;
    }

    /** @return list<array<string, mixed>> */
    public function fetchAllAssociative(): array
    {
        $rows = [];

        while (($row = $this->fetchAssociative()) !== false) {
            $rows[] = $row;
        }

        return $rows;
    }

    /** @return list<mixed> */
    public function fetchFirstColumn(): array
    {
        $values = [];

        while (($row = $this->fetchNumeric()) !== false) {
            $values[] = $row[0];
        }

        return $values;
    }

    public function rowCount(): int
    {
        if ($this->result === null) {
            return 0;
        }

        if (in_array($this->result->statementType(), self::DML, true)) {
            return $this->result->rowsChanged();
        }

        return $this->result->rowCount();
    }

    public function columnCount(): int
    {
        return $this->result?->columnCount() ?? 0;
    }

    public function getColumnName(int $index): string
    {
        $names = $this->names();

        if (! isset($names[$index])) {
            throw InvalidColumnIndex::new($index);
        }

        return $names[$index];
    }

    public function free(): void
    {
        $this->result = null;
    }

    /** @return list<string> */
    private function names(): array
    {
        if ($this->names !== null) {
            return $this->names;
        }

        $names = [];

        for ($i = 0, $count = $this->columnCount(); $i < $count; $i++) {
            $names[] = $this->result?->columnName($i) ?? '';
        }

        return $this->names = $names;
    }

    /** @return list<Closure(mixed): mixed> */
    private function normalizers(): array
    {
        if ($this->normalizers !== null) {
            return $this->normalizers;
        }

        $normalizers = [];

        for ($i = 0, $count = count($this->names()); $i < $count; $i++) {
            $normalizers[] = ValueNormalizer::forColumnType($this->result?->columnType($i) ?? '');
        }

        return $this->normalizers = $normalizers;
    }
}
