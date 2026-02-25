<?php

declare(strict_types=1);

namespace App\Repository\Sde;

use App\Entity\Sde\IndustryActivity;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<IndustryActivity>
 */
class IndustryActivityRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, IndustryActivity::class);
    }

    /**
     * @return IndustryActivity[]
     */
    public function findByTypeId(int $typeId): array
    {
        return $this->findBy(['typeId' => $typeId]);
    }

    /**
     * Bulk-fetch activities for multiple (typeId, activityId) pairs in a single query.
     *
     * @param int[] $typeIds blueprint type IDs
     * @param int[] $activityIds activity IDs
     * @return array<string, IndustryActivity> keyed by "typeId-activityId"
     */
    public function findByTypeIdsAndActivityIds(array $typeIds, array $activityIds): array
    {
        if (empty($typeIds) || empty($activityIds)) {
            return [];
        }

        $activities = $this->createQueryBuilder('a')
            ->where('a.typeId IN (:typeIds)')
            ->andWhere('a.activityId IN (:activityIds)')
            ->setParameter('typeIds', array_values(array_unique($typeIds)))
            ->setParameter('activityIds', array_values(array_unique($activityIds)))
            ->getQuery()
            ->getResult();

        $indexed = [];
        foreach ($activities as $activity) {
            $key = $activity->getTypeId() . '-' . $activity->getActivityId();
            $indexed[$key] = $activity;
        }

        return $indexed;
    }
}
