<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\IndustryRigCategory;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<IndustryRigCategory>
 */
class IndustryRigCategoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, IndustryRigCategory::class);
    }

    /**
     * Build a map of groupId => category for fast lookups.
     * @return array<int, string>
     */
    public function buildGroupCategoryMap(): array
    {
        $results = $this->createQueryBuilder('c')
            ->select('c.groupId, c.category')
            ->getQuery()
            ->getArrayResult();

        $map = [];
        foreach ($results as $row) {
            $map[$row['groupId']] = $row['category'];
        }
        return $map;
    }
}
