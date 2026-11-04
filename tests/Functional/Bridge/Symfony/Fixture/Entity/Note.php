<?php

declare(strict_types=1);

namespace MartinJuul\DBAL\DuckDB\Tests\Functional\Bridge\Symfony\Fixture\Entity;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\Table;

/** An entity with a default (AUTO) generated identifier. */
#[Entity]
#[Table(name: 'note')]
class Note
{
    #[Id]
    #[GeneratedValue]
    #[Column(type: 'integer')]
    public int|null $id = null;

    public function __construct(
        #[Column(type: 'string')]
        public string $text,
    ) {
    }
}
