<?php

declare(strict_types=1);

namespace MartinJuul\DBAL\DuckDB\Tests\Functional\Orm;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\Logging\Middleware as LoggingMiddleware;
use Doctrine\DBAL\Platforms\Exception\NotSupported;
use MartinJuul\DBAL\DuckDB\Driver;
use MartinJuul\DBAL\DuckDB\Tests\Functional\Orm\Fixture\Account;
use Psr\Log\AbstractLogger;
use Stringable;
use Throwable;

use function is_string;

final class PessimisticLockTest extends OrmTestCase
{
    /** AE3, ORM half: the lock is refused before any SQL reaches DuckDB. */
    public function testFindWithAPessimisticWriteLockIsRejected(): void
    {
        $logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $statements = [];

            /** @param array<mixed> $context */
            public function log(mixed $level, string|Stringable $message, array $context = []): void
            {
                if (! isset($context['sql']) || ! is_string($context['sql'])) {
                    return;
                }

                $this->statements[] = $context['sql'];
            }
        };

        $configuration = new Configuration();
        $configuration->setMiddlewares([new LoggingMiddleware($logger)]);
        $connection = DriverManager::getConnection(['driverClass' => Driver::class, 'memory' => true], $configuration);
        self::createAccountTable($connection);
        $connection->executeStatement("INSERT INTO account VALUES (1, 'Ada')");
        $entityManager = self::createEntityManager($connection);

        self::assertContains("INSERT INTO account VALUES (1, 'Ada')", $logger->statements);

        $entityManager->beginTransaction();
        $logger->statements = [];

        $rejection = null;

        try {
            $entityManager->find(Account::class, 1, LockMode::PESSIMISTIC_WRITE);
        } catch (Throwable $rejection) {
        }

        self::assertInstanceOf(NotSupported::class, $rejection);
        self::assertStringContainsString('does not support row locks', $rejection->getMessage());

        self::assertSame([], $logger->statements);

        $entityManager->rollback();
    }
}
