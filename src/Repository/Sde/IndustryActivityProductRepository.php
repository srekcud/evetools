<?php

declare(strict_types=1);

namespace App\Repository\Sde;

use App\Entity\Sde\IndustryActivityProduct;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;

/**
 * @extends ServiceEntityRepository<IndustryActivityProduct>
 */
class IndustryActivityProductRepository extends ServiceEntityRepository
{
    public function __construct(
        ManagerRegistry $registry,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct($registry, IndustryActivityProduct::class);
    }

    /**
     * Find all manufacturable/reactable products (published, with market group).
     *
     * @return list<array{blueprintTypeId: int, productTypeId: int, outputPerRun: int, activityId: int}>
     */
    public function findAllManufacturableProducts(): array
    {
        $conn = $this->getEntityManager()->getConnection();

        $sql = <<<SQL
            SELECT
                p.type_id AS blueprint_type_id,
                p.product_type_id,
                p.quantity AS output_per_run,
                p.activity_id
            FROM sde_industry_activity_products p
            JOIN sde_inv_types t ON t.type_id = p.product_type_id
            JOIN sde_inv_groups g ON g.group_id = t.group_id
            JOIN sde_inv_categories c ON c.category_id = g.category_id
            WHERE p.activity_id IN (1, 11)
              AND t.published = true
              AND t.market_group_id IS NOT NULL
              AND c.category_id NOT IN (2, 25)
        SQL;

        $rows = $conn->fetchAllAssociative($sql);

        return array_map(static fn (array $row) => [
            'blueprintTypeId' => (int) $row['blueprint_type_id'],
            'productTypeId' => (int) $row['product_type_id'],
            'outputPerRun' => (int) $row['output_per_run'],
            'activityId' => (int) $row['activity_id'],
        ], $rows);
    }

    /**
     * Find blueprint that produces a given type.
     * If multiple blueprints exist (test blueprints in SDE), logs a warning
     * and returns the one with the highest output per run.
     */
    /**
     * Find which blueprint IDs are produced via invention (activity_id=8).
     * Used to identify T2 blueprints from a batch of blueprint IDs.
     *
     * @param int[] $blueprintTypeIds Blueprint type IDs to check
     * @return array<int, true> Set of blueprint type IDs that are invented (T2)
     */
    public function findInventedBlueprintIds(array $blueprintTypeIds): array
    {
        if (empty($blueprintTypeIds)) {
            return [];
        }

        $conn = $this->getEntityManager()->getConnection();
        $placeholders = implode(',', array_fill(0, count($blueprintTypeIds), '?'));

        $sql = <<<SQL
            SELECT DISTINCT product_type_id
            FROM sde_industry_activity_products
            WHERE activity_id = 8
              AND product_type_id IN ({$placeholders})
        SQL;

        $rows = $conn->fetchFirstColumn($sql, array_values($blueprintTypeIds));

        $result = [];
        foreach ($rows as $bpId) {
            $result[(int) $bpId] = true;
        }

        return $result;
    }

    /**
     * Bulk-fetch products for multiple blueprints, indexed by "typeId-activityId".
     *
     * Returns one product per (typeId, activityId) pair (the first found).
     *
     * @param int[] $blueprintTypeIds
     * @param int[] $activityIds
     * @return array<string, IndustryActivityProduct> keyed by "typeId-activityId"
     */
    public function findProductsForBlueprints(array $blueprintTypeIds, array $activityIds): array
    {
        if (empty($blueprintTypeIds) || empty($activityIds)) {
            return [];
        }

        $products = $this->createQueryBuilder('p')
            ->where('p.typeId IN (:typeIds)')
            ->andWhere('p.activityId IN (:activityIds)')
            ->setParameter('typeIds', array_values(array_unique($blueprintTypeIds)))
            ->setParameter('activityIds', array_values(array_unique($activityIds)))
            ->getQuery()
            ->getResult();

        $indexed = [];
        foreach ($products as $product) {
            $key = $product->getTypeId() . '-' . $product->getActivityId();
            // Keep first found (consistent with findOneBy behavior)
            if (!isset($indexed[$key])) {
                $indexed[$key] = $product;
            }
        }

        return $indexed;
    }

    /**
     * Find faction blueprint IDs among the given manufacturing blueprints.
     *
     * A blueprint is "faction" if it manufactures a product with sofFactionName set
     * AND the blueprint is NOT invented (not T2).
     *
     * @param int[] $manufacturingBlueprintTypeIds Blueprint IDs that have activity_id=1
     * @return array<int, true> Set of faction blueprint type IDs
     */
    public function findFactionBlueprintIds(array $manufacturingBlueprintTypeIds): array
    {
        if (empty($manufacturingBlueprintTypeIds)) {
            return [];
        }

        // Get invented blueprints to exclude them (T2 items also have sofFactionName sometimes)
        $inventedBlueprintIds = $this->findInventedBlueprintIds($manufacturingBlueprintTypeIds);

        // Filter out invented blueprints
        $nonInventedBpIds = array_diff($manufacturingBlueprintTypeIds, array_keys($inventedBlueprintIds));

        if (empty($nonInventedBpIds)) {
            return [];
        }

        $conn = $this->getEntityManager()->getConnection();
        $placeholders = implode(',', array_fill(0, count($nonInventedBpIds), '?'));

        // Find blueprints whose manufactured product has sofFactionName set
        $sql = <<<SQL
            SELECT DISTINCT p.type_id AS blueprint_type_id
            FROM sde_industry_activity_products p
            JOIN sde_inv_types t ON t.type_id = p.product_type_id
            WHERE p.activity_id = 1
              AND p.type_id IN ({$placeholders})
              AND t.sof_faction_name IS NOT NULL
        SQL;

        $rows = $conn->fetchFirstColumn($sql, array_values($nonInventedBpIds));

        $result = [];
        foreach ($rows as $bpId) {
            $result[(int) $bpId] = true;
        }

        return $result;
    }

    public function findBlueprintForProduct(int $productTypeId, int $activityId = 1): ?IndustryActivityProduct
    {
        $results = $this->findBy(
            ['productTypeId' => $productTypeId, 'activityId' => $activityId],
            ['quantity' => 'DESC'],
        );

        if (count($results) === 0) {
            return null;
        }

        if (count($results) > 1) {
            $this->logger->warning('Multiple blueprints found for same product, using highest output (likely test blueprints in SDE)', [
                'productTypeId' => $productTypeId,
                'activityId' => $activityId,
                'count' => count($results),
                'selectedQuantity' => $results[0]->getQuantity(),
            ]);
        }

        return $results[0];
    }
}
