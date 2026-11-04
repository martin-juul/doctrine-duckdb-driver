<?php

declare(strict_types=1);

namespace MartinJuul\DBAL\DuckDB\Tests\Functional\Orm\Fixture;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\Table;

/** An entity with LIST, STRUCT and MAP fields. */
#[Entity]
#[Table(name: 'measurement')]
class Measurement
{
    /**
     * @param list<int>                                   $readings
     * @param array{station: string, elevation: int}|null $origin
     * @param array<string, int>                          $counts
     */
    public function __construct(
        #[Id]
        #[GeneratedValue(strategy: 'NONE')]
        #[Column(type: 'integer')]
        public int $id,
        #[Column(type: 'duckdb_list', options: ['element_type' => 'INTEGER'])]
        public array $readings,
        #[Column(
            type: 'duckdb_struct',
            nullable: true,
            options: ['fields' => ['station' => 'VARCHAR', 'elevation' => 'INTEGER']],
        )]
        public array|null $origin,
        #[Column(type: 'duckdb_map', options: ['key_type' => 'VARCHAR', 'value_type' => 'INTEGER'])]
        public array $counts,
    ) {
    }
}
