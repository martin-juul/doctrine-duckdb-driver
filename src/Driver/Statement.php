<?php

declare(strict_types=1);

namespace MartinJuul\DBAL\DuckDB\Driver;

use Doctrine\DBAL\Driver\Statement as StatementInterface;
use Doctrine\DBAL\ParameterType;
use DuckDB\Statement as NativeStatement;
use DuckDB\Value;
use MartinJuul\DBAL\DuckDB\Driver\Exception\DriverError;
use MartinJuul\DBAL\DuckDB\Driver\Exception\InvalidUtf8Parameter;
use Stringable;
use TypeError;
use ValueError;

use function DuckDB\isValidUtf8;
use function is_bool;
use function is_resource;
use function is_string;
use function stream_get_contents;

final class Statement implements StatementInterface
{
    /** @var array<int|string, array{mixed, ParameterType}> */
    private array $parameters = [];

    public function __construct(
        private readonly NativeStatement $statement,
        private readonly TransactionState $transaction,
    ) {
    }

    public function bindValue(int|string $param, mixed $value, ParameterType $type): void
    {
        $this->parameters[$param] = [$value, $type];
    }

    /** Refused while the transaction is rollback-only, like every statement of the connection. */
    public function execute(): Result
    {
        return $this->transaction->run(function (): Result {
            try {
                $this->statement->clearBindings();

                foreach ($this->parameters as $param => [$value, $type]) {
                    $this->bind($param, $value, $type);
                }

                return new Result($this->statement->execute());
            } catch (ValueError | TypeError $e) {
                throw DriverError::fromBinding($e);
            }
        });
    }

    private function bind(int|string $param, mixed $value, ParameterType $type): void
    {
        if ($value === null || $type === ParameterType::NULL) {
            $this->statement->bindValue($param, null);

            return;
        }

        switch ($type) {
            case ParameterType::BINARY:
            case ParameterType::LARGE_OBJECT:
                if (is_resource($value)) {
                    $value = stream_get_contents($value);
                }

                if (is_string($value)) {
                    $this->statement->bindBlob($param, $value);

                    return;
                }

                break;

            case ParameterType::INTEGER:
                if (is_string($value) || is_bool($value)) {
                    $value = (int) $value;
                }

                break;

            case ParameterType::BOOLEAN:
                $value = (bool) $value;

                break;

            case ParameterType::STRING:
            case ParameterType::ASCII:
                // Like PDO, a string parameter binds an object's string form; typed values bind unchanged (KTD6).
                if ($value instanceof Stringable && ! $value instanceof Value) {
                    $value = (string) $value;
                }

                if (is_string($value) && ! isValidUtf8($value)) {
                    throw InvalidUtf8Parameter::forParameter($param);
                }

                break;
        }

        $this->statement->bindValue($param, $value);
    }
}
