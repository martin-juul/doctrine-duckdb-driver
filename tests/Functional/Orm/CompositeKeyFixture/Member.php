<?php

declare(strict_types=1);

namespace MartinJuul\DBAL\DuckDB\Tests\Functional\Orm\CompositeKeyFixture;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\InverseJoinColumn;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\JoinTable;
use Doctrine\ORM\Mapping\ManyToMany;
use Doctrine\ORM\Mapping\Table;

/**
 * A composite primary key (uid, account_id) referenced by join columns listed
 * as (account_id, uid), as in ORM's DDC2252Test.
 */
#[Entity]
#[Table(name: 'member')]
class Member
{
    /** @var Collection<int, Privilege> */
    #[ManyToMany(targetEntity: Privilege::class)]
    #[JoinTable(name: 'member_privilege')]
    #[JoinColumn(name: 'account_id', referencedColumnName: 'account_id')]
    #[JoinColumn(name: 'uid', referencedColumnName: 'uid')]
    #[InverseJoinColumn(name: 'privilege_id', referencedColumnName: 'id')]
    public Collection $privileges;

    public function __construct(
        #[Id]
        #[Column(type: 'integer')]
        public int $uid,
        #[Id]
        #[Column(name: 'account_id', type: 'integer')]
        public int $accountId,
    ) {
        $this->privileges = new ArrayCollection();
    }
}
