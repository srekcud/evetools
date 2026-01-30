<?php

declare(strict_types=1);

namespace App\Repository\Sde;

use App\Entity\Sde\InvMarketGroup;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<InvMarketGroup>
 */
class InvMarketGroupRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InvMarketGroup::class);
    }

    /**
     * @return InvMarketGroup[]
     */
    public function findRootGroups(): array
    {
        return $this->createQueryBuilder('mg')
            ->where('mg.parentGroup IS NULL')
            ->orderBy('mg.marketGroupName', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function truncate(): void
    {
        $this->createQueryBuilder('mg')
            ->delete()
            ->getQuery()
            ->execute();
    }
}
