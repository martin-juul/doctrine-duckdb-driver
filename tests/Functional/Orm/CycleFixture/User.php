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

/** A user whose nullable `lastPost` references a post, while each post references its author. */
#[Entity]
#[Table(name: 'cycle_user')]
class User
{
    #[Id]
    #[GeneratedValue]
    #[Column(type: 'integer')]
    public int|null $id = null;

    #[ManyToOne(targetEntity: Post::class)]
    #[JoinColumn(name: 'last_post_id')]
    public Post|null $lastPost = null;

    public function __construct(
        #[Column(type: 'string')]
        public string $name,
    ) {
    }
}
