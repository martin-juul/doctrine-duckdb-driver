<?php

declare(strict_types=1);

namespace MartinJuul\DBAL\DuckDB\Tests\Functional\Orm\ReferentialActionFixture;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Table;

#[Entity]
#[Table(name: 'car')]
class Car extends Vehicle
{
    public function __construct(
        string $name,
        #[Column(type: 'integer')]
        public int $doors,
    ) {
        parent::__construct($name);
    }
}
