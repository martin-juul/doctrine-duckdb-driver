<?php

declare(strict_types=1);

namespace MartinJuul\DBAL\DuckDB\Tests\Functional\Orm\ReferentialActionFixture;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Table;

#[Entity]
#[Table(name: 'sports_car')]
class SportsCar extends Car
{
    public function __construct(
        string $name,
        int $doors,
        #[Column(type: 'integer')]
        public int $topSpeed,
    ) {
        parent::__construct($name, $doors);
    }
}
