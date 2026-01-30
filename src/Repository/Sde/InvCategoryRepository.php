<?php

declare(strict_types=1);

namespace App\Repository\Sde;

use App\Entity\Sde\InvCategory;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<InvCategory>
 */
class InvCategoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InvCategory::class);
    }

    public function truncate(): void
    {
        $this->createQueryBuilder('c')
            ->delete()
            ->getQuery()
            ->execute();
    }
}
