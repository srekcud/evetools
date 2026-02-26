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

    /**
     * Batch-fetch required skills for multiple blueprints.
     *
     * @param int[] $blueprintTypeIds
     * @return array<int, list<array{skillId: int, level: int}>> blueprintTypeId => required skills
     */
    public function findSkillsForBlueprints(array $blueprintTypeIds, int $activityId = 1): array
    {
        if (empty($blueprintTypeIds)) {
            return [];
        }

        $conn = $this->getEntityManager()->getConnection();
        $placeholders = implode(',', array_fill(0, count($blueprintTypeIds), '?'));

        $sql = <<<SQL
            SELECT type_id, skill_id, level
            FROM sde_industry_activity_skills
            WHERE type_id IN ({$placeholders})
              AND activity_id = ?
        SQL;

        $params = array_values($blueprintTypeIds);
        $params[] = $activityId;

        $rows = $conn->fetchAllAssociative($sql, $params);

        $result = [];
        foreach ($rows as $row) {
            $typeId = (int) $row['type_id'];
            $result[$typeId][] = [
                'skillId' => (int) $row['skill_id'],
                'level' => (int) $row['level'],
            ];
        }

        return $result;
    }
}
