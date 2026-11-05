<?php

declare(strict_types=1);

namespace MartinJuul\DBAL\DuckDB\Tests\Functional\Orm\CycleFixture;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\Table;

/** A post, whose author is required. */
#[Entity]
#[Table(name: 'cycle_post')]
class Post
{
    #[Id]
    #[GeneratedValue]
    #[Column(type: 'integer')]
    public int|null $id = null;

    public function __construct(
        #[Column(type: 'string')]
        public string $title,
        #[ManyToOne(targetEntity: User::class)]
        #[JoinColumn(nullable: false)]
        public User $author,
    ) {
    }
}
