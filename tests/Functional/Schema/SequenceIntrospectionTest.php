<?php

declare(strict_types=1);

namespace MartinJuul\DBAL\DuckDB\Tests\Functional\Schema;

use Doctrine\DBAL\Schema\Name\OptionallyQualifiedName;
use Doctrine\DBAL\Schema\Sequence;
use MartinJuul\DBAL\DuckDB\Tests\Functional\FunctionalTestCase;

final class SequenceIntrospectionTest extends FunctionalTestCase
{
    /**
     * DuckDB writes a sequence back with its start value set to the next
     * counter value, so after a reopen the stored start value no longer
     * matches the declaration. Using a sequence must not make the schema differ.
     */
    public function testUsedSequenceKeepsItsInitialValueAfterReopen(): void
    {
        $path       = $this->temporaryDatabasePath();
        $connection = self::connect(['dbname' => $path]);
        $platform   = $connection->getDatabasePlatform();

        $declared = Sequence::editor()
            ->setName(OptionallyQualifiedName::unquoted('ticket_id_seq'))
            ->setAllocationSize(1)
            ->setInitialValue(1)
            ->create();

        $connection->executeStatement($platform->getCreateSequenceSQL($declared));
        $connection->fetchOne($platform->getSequenceNextValSQL('ticket_id_seq'));
        $connection->fetchOne($platform->getSequenceNextValSQL('ticket_id_seq'));
        $connection->close();
        unset($connection);

        $reopened     = self::connect(['dbname' => $path]);
        $sequences    = $reopened->createSchemaManager()->introspectSequences();
        $introspected = null;

        foreach ($sequences as $sequence) {
            if ($sequence->getObjectName()->getUnqualifiedName()->getValue() !== 'ticket_id_seq') {
                continue;
            }

            $introspected = $sequence;
        }

        self::assertNotNull($introspected);
        self::assertSame(1, $introspected->getInitialValue());
        self::assertSame(3, $reopened->fetchOne($platform->getSequenceNextValSQL('ticket_id_seq')));
    }
}
