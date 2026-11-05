<?php

declare(strict_types=1);

namespace MartinJuul\DBAL\DuckDB\Tests\Functional\Orm\ReferentialActionFixture;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToMany;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\Table;

/**
 * An entity with an association mapped `onDelete: 'CASCADE'`, an association
 * without a referential action, and a default many-to-many association, whose
 * join columns ORM declares with ON DELETE CASCADE.
 */
#[Entity]
#[Table(name: 'book')]
class Book
{
    #[Id]
    #[GeneratedValue]
    #[Column(type: 'integer')]
    public int|null $id = null;

    /** @var Collection<int, Tag> */
    #[ManyToMany(targetEntity: Tag::class)]
    public Collection $tags;

    public function __construct(
        #[Column(type: 'string')]
        public string $title,
        #[ManyToOne(targetEntity: Author::class)]
        #[JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public Author $author,
        #[ManyToOne(targetEntity: Publisher::class)]
        #[JoinColumn(nullable: false)]
        public Publisher $publisher,
    ) {
        $this->tags = new ArrayCollection();
    }
}
