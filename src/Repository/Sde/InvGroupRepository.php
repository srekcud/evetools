<?php

declare(strict_types=1);

namespace App\Repository\Sde;

use App\Entity\Sde\InvGroup;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<InvGroup>
 */
class InvGroupRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InvGroup::class);
    }

    public function truncate(): void
    {
        $this->createQueryBuilder('g')
            ->delete()
            ->getQuery()
            ->execute();
    }
}
