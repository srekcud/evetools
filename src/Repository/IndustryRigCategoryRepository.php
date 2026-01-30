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
     * Find the category for a given SDE group ID.
     */
    public function findCategoryByGroupId(int $groupId): ?string
    {
        $result = $this->createQueryBuilder('c')
            ->select('c.category')
            ->where('c.groupId = :groupId')
            ->setParameter('groupId', $groupId)
            ->getQuery()
            ->getOneOrNullResult();

        return $result['category'] ?? null;
    }

    /**
     * Get all group IDs for a category.
     * @return int[]
     */
    public function findGroupIdsByCategory(string $category): array
    {
        $results = $this->createQueryBuilder('c')
            ->select('c.groupId')
            ->where('c.category = :category')
            ->setParameter('category', $category)
            ->getQuery()
            ->getArrayResult();

        return array_column($results, 'groupId');
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
