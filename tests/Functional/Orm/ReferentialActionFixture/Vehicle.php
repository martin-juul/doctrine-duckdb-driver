<?php

declare(strict_types=1);

namespace MartinJuul\DBAL\DuckDB\Tests\Functional\Orm\ReferentialActionFixture;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\DiscriminatorColumn;
use Doctrine\ORM\Mapping\DiscriminatorMap;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\InheritanceType;
use Doctrine\ORM\Mapping\Table;

/** The root of a JOINED hierarchy: ORM declares ON DELETE CASCADE from each subclass table to this table. */
#[Entity]
#[Table(name: 'vehicle')]
#[InheritanceType('JOINED')]
#[DiscriminatorColumn(name: 'kind', type: 'string')]
#[DiscriminatorMap(['vehicle' => Vehicle::class, 'car' => Car::class, 'sports_car' => SportsCar::class])]
class Vehicle
{
    #[Id]
    #[GeneratedValue]
    #[Column(type: 'integer')]
    public int|null $id = null;

    public function __construct(
        #[Column(type: 'string')]
        public string $name,
    ) {
    }
}
