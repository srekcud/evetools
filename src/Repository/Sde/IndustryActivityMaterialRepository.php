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

    /**
     * Bulk-fetch materials for multiple blueprints in a single query.
     *
     * @param int[] $blueprintTypeIds
     * @param int[] $activityIds Activity IDs to include (e.g. [1, 11])
     * @return array<int, list<array{materialTypeId: int, quantity: int}>> Indexed by blueprintTypeId
     */
    public function findMaterialsForBlueprints(array $blueprintTypeIds, array $activityIds): array
    {
        if (empty($blueprintTypeIds) || empty($activityIds)) {
            return [];
        }

        $conn = $this->getEntityManager()->getConnection();

        $bpPlaceholders = implode(',', array_fill(0, count($blueprintTypeIds), '?'));
        $actPlaceholders = implode(',', array_fill(0, count($activityIds), '?'));

        $sql = <<<SQL
            SELECT type_id, material_type_id, quantity
            FROM sde_industry_activity_materials
            WHERE type_id IN ({$bpPlaceholders})
              AND activity_id IN ({$actPlaceholders})
        SQL;

        $params = array_merge(
            array_values(array_map('intval', $blueprintTypeIds)),
            array_values(array_map('intval', $activityIds)),
        );

        $rows = $conn->fetchAllAssociative($sql, $params);

        $result = [];
        foreach ($rows as $row) {
            $bpId = (int) $row['type_id'];
            $result[$bpId][] = [
                'materialTypeId' => (int) $row['material_type_id'],
                'quantity' => (int) $row['quantity'],
            ];
        }

        return $result;
    }

    /**
     * Bulk-fetch material entities for multiple blueprints, grouped by "typeId-activityId".
     *
     * @param int[] $blueprintTypeIds
     * @param int[] $activityIds
     * @return array<string, list<IndustryActivityMaterial>> keyed by "typeId-activityId"
     */
    public function findMaterialEntitiesForBlueprints(array $blueprintTypeIds, array $activityIds): array
    {
        if (empty($blueprintTypeIds) || empty($activityIds)) {
            return [];
        }

        $materials = $this->createQueryBuilder('m')
            ->where('m.typeId IN (:typeIds)')
            ->andWhere('m.activityId IN (:activityIds)')
            ->setParameter('typeIds', array_values(array_unique($blueprintTypeIds)))
            ->setParameter('activityIds', array_values(array_unique($activityIds)))
            ->getQuery()
            ->getResult();

        $grouped = [];
        foreach ($materials as $material) {
            $key = $material->getTypeId() . '-' . $material->getActivityId();
            $grouped[$key][] = $material;
        }

        return $grouped;
    }

    /**
     * Reverse lookup: find all products whose blueprint requires any of the given materials.
     * Returns the same shape as findAllManufacturableProducts().
     *
     * @param int[] $materialTypeIds
     * @param int[] $activityIds Activity IDs (e.g. [1, 11] for manufacturing + reaction)
     * @return list<array{blueprintTypeId: int, productTypeId: int, outputPerRun: int, activityId: int}>
     */
    public function findProductsUsingMaterials(array $materialTypeIds, array $activityIds): array
    {
        if (empty($materialTypeIds) || empty($activityIds)) {
            return [];
        }

        $conn = $this->getEntityManager()->getConnection();

        $matPlaceholders = implode(',', array_fill(0, count($materialTypeIds), '?'));
        $actPlaceholders = implode(',', array_fill(0, count($activityIds), '?'));

        $sql = <<<SQL
            SELECT DISTINCT
                p.type_id AS blueprint_type_id,
                p.product_type_id,
                p.quantity AS output_per_run,
                p.activity_id
            FROM sde_industry_activity_materials m
            JOIN sde_industry_activity_products p
                ON p.type_id = m.type_id
                AND p.activity_id = m.activity_id
            JOIN sde_inv_types t ON t.type_id = p.product_type_id
            WHERE m.material_type_id IN ({$matPlaceholders})
              AND m.activity_id IN ({$actPlaceholders})
              AND t.published = true
              AND t.market_group_id IS NOT NULL
        SQL;

        $params = array_merge(
            array_values(array_map('intval', $materialTypeIds)),
            array_values(array_map('intval', $activityIds)),
        );

        $rows = $conn->fetchAllAssociative($sql, $params);

        return array_map(static fn (array $row) => [
            'blueprintTypeId' => (int) $row['blueprint_type_id'],
            'productTypeId' => (int) $row['product_type_id'],
            'outputPerRun' => (int) $row['output_per_run'],
            'activityId' => (int) $row['activity_id'],
        ], $rows);
    }
}
