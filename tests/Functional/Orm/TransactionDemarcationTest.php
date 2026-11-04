<?php

declare(strict_types=1);

namespace MartinJuul\DBAL\DuckDB\Tests\Functional\Orm;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use MartinJuul\DBAL\DuckDB\Tests\Functional\Orm\Fixture\Account;

final class TransactionDemarcationTest extends OrmTestCase
{
    /** AE10: flush() opens a nested level inside wrapInTransaction(); the outer level commits. */
    public function testWrapInTransactionCommitsTheFlushedEntity(): void
    {
        [$entityManager, $observer] = $this->createEntityManagerAndObserver();

        $entityManager->wrapInTransaction(static function (EntityManagerInterface $entityManager) use ($observer): void {
            $entityManager->persist(new Account(1, 'Ada'));
            $entityManager->flush();

            self::assertSame(0, $observer->fetchOne('SELECT count(*) FROM account'));
        });

        self::assertSame(0, $entityManager->getConnection()->getTransactionNestingLevel());
        self::assertSame(
            [['id' => 1, 'name' => 'Ada']],
            $observer->fetchAllAssociative('SELECT id, name FROM account'),
        );
    }

    public function testFlushInsideAnExplicitTransactionCommits(): void
    {
        [$entityManager, $observer] = $this->createEntityManagerAndObserver();

        $entityManager->beginTransaction();
        $entityManager->persist(new Account(1, 'Ada'));
        $entityManager->flush();

        self::assertSame(0, $observer->fetchOne('SELECT count(*) FROM account'));

        $entityManager->commit();

        self::assertSame(1, $observer->fetchOne('SELECT count(*) FROM account'));
    }

    public function testFlushInsideAnExplicitTransactionRollsBack(): void
    {
        [$entityManager, $observer] = $this->createEntityManagerAndObserver();

        $entityManager->beginTransaction();
        $entityManager->persist(new Account(1, 'Ada'));
        $entityManager->flush();
        $entityManager->rollback();

        self::assertSame(0, $entityManager->getConnection()->getTransactionNestingLevel());
        self::assertSame(0, $observer->fetchOne('SELECT count(*) FROM account'));
    }

    /**
     * An entity manager and a second connection that observes what it commits,
     * both on one new database file holding the account table.
     *
     * @return array{EntityManagerInterface, Connection}
     */
    private function createEntityManagerAndObserver(): array
    {
        $path     = $this->temporaryDatabasePath();
        $observer = self::connect(['dbname' => $path]);
        self::createAccountTable($observer);

        return [self::createEntityManager(self::connect(['dbname' => $path])), $observer];
    }
}
