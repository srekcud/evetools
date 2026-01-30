<?php

declare(strict_types=1);

namespace App\Repository\Sde;

use App\Entity\Sde\IndustryActivitySkill;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<IndustryActivitySkill>
 */
class IndustryActivitySkillRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, IndustryActivitySkill::class);
    }

    /**
     * @return IndustryActivitySkill[]
     */
    public function findByBlueprintAndActivity(int $typeId, int $activityId = 1): array
    {
        return $this->findBy([
            'typeId' => $typeId,
            'activityId' => $activityId,
        ]);
    }
}
