<?php

declare(strict_types=1);

namespace App\Repository\Sde;

use App\Entity\Sde\IndustryActivityMaterial;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<IndustryActivityMaterial>
 */
class IndustryActivityMaterialRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, IndustryActivityMaterial::class);
    }

    /**
     * Get materials for a blueprint activity (usually activityId=1 for manufacturing)
     * @return IndustryActivityMaterial[]
     */
    public function findByBlueprintAndActivity(int $typeId, int $activityId = 1): array
    {
        return $this->findBy([
            'typeId' => $typeId,
            'activityId' => $activityId,
        ]);
    }
}
