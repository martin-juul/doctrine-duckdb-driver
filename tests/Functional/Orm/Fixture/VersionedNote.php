<?php

declare(strict_types=1);

namespace MartinJuul\DBAL\DuckDB\Tests\Functional\Orm\Fixture;

use DateTime;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\Table;
use Doctrine\ORM\Mapping\Version;

/** An entity whose version is a datetime, which the database sets to CURRENT_TIMESTAMP. */
#[Entity]
#[Table(name: 'versioned_note')]
class VersionedNote
{
    #[Column(type: 'datetime')]
    #[Version]
    public DateTime|null $version = null;

    public function __construct(
        #[Id]
        #[GeneratedValue(strategy: 'NONE')]
        #[Column(type: 'integer')]
        public int $id,
        #[Column(type: 'datetime')]
        public DateTime $lastUpdate,
    ) {
    }
}
