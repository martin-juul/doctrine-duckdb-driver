<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'orders')]
class Order
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private int|null $id = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'orders')]
        #[ORM\JoinColumn(nullable: false)]
        private User $customer,
        #[ORM\Column(length: 20)]
        private string $reference,
        #[ORM\Column]
        private int $amountCents,
    ) {
    }

    public function getId(): int|null
    {
        return $this->id;
    }

    public function getCustomer(): User
    {
        return $this->customer;
    }

    public function getReference(): string
    {
        return $this->reference;
    }

    public function getAmountCents(): int
    {
        return $this->amountCents;
    }

    public function changeAmount(int $amountCents): void
    {
        $this->amountCents = $amountCents;
    }
}
