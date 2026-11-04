<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class Category
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private int|null $id = null;

    /** @var Collection<int, Category> */
    #[ORM\OneToMany(targetEntity: self::class, mappedBy: 'parent')]
    #[ORM\OrderBy(['name' => 'ASC'])]
    private Collection $children;

    public function __construct(
        #[ORM\Column(length: 100)]
        private string $name,
        #[ORM\ManyToOne(targetEntity: self::class, inversedBy: 'children')]
        private Category|null $parent = null,
    ) {
        $this->children = new ArrayCollection();
    }

    public function getId(): int|null
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function rename(string $name): void
    {
        $this->name = $name;
    }

    public function getParent(): Category|null
    {
        return $this->parent;
    }

    public function moveTo(Category|null $parent): void
    {
        $this->parent = $parent;
    }

    /** @return Collection<int, Category> */
    public function getChildren(): Collection
    {
        return $this->children;
    }
}
