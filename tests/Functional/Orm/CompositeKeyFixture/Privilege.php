<?php

declare(strict_types=1);

namespace MartinJuul\DBAL\DuckDB\Tests\Functional\Orm\CompositeKeyFixture;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\Table;

#[Entity]
#[Table(name: 'privilege')]
class Privilege
{
    public function __construct(
        #[Id]
        #[Column(type: 'integer')]
        public int $id,
    ) {
    }
}
