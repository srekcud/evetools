<?php

declare(strict_types=1);

namespace App\Service\Industry;

use App\Entity\User;
use App\Repository\Sde\IndustryActivityMaterialRepository;
use App\Repository\Sde\IndustryActivityProductRepository;
use App\Repository\Sde\InvTypeRepository;
use Doctrine\ORM\EntityManagerInterface;

class IndustryTreeService
{
    // EVE SDE activity IDs
    private const ACTIVITY_MANUFACTURING = 1;
    private const ACTIVITY_COPYING = 5;
    private const ACTIVITY_REACTION = 11;

    public function __construct(
        private readonly IndustryActivityProductRepository $activityProductRepository,
        private readonly IndustryActivityMaterialRepository $activityMaterialRepository,
        private readonly InvTypeRepository $invTypeRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly IndustryBonusService $bonusService,
    ) {
    }

    /**
     * Build a full production tree for a product, including reactions.
     *
     * @param int[] $excludedTypeIds Type IDs to treat as raw materials (not expanded)
     * @param User|null $user User for structure bonus calculation (optional)
     */
    public function buildProductionTree(int $productTypeId, int $runs = 1, int $finalMe = 0, array $excludedTypeIds = [], ?User $user = null): array
    {
        return $this->buildNode($productTypeId, $runs, $finalMe, 0, $excludedTypeIds, $user, true);
    }

    private function buildNode(int $productTypeId, int $quantity, int $meLevel, int $depth, array $excludedTypeIds, ?User $user, bool $isRoot = false): array
    {
        // Find the blueprint/formula that produces this type (manufacturing or reaction)
        $product = $this->findProducerFor($productTypeId);

        if ($product === null) {
            throw new \RuntimeException("No blueprint or reaction found for product type ID {$productTypeId}");
        }

        $blueprintTypeId = $product->getTypeId();
        $activityId = $product->getActivityId();
        $outputPerRun = $product->getQuantity();
        $runs = (int) ceil($quantity / $outputPerRun);

        $type = $this->invTypeRepository->find($productTypeId);
        $productTypeName = $type?->getTypeName() ?? "Type #{$productTypeId}";

        $activityType = match ($activityId) {
            self::ACTIVITY_REACTION => 'reaction',
            self::ACTIVITY_COPYING => 'copy',
            default => 'manufacturing',
        };

        // Get structure bonus for this product
        $structureBonus = 0.0;
        $structureName = null;
        $productCategory = null;

        if ($user !== null) {
            $isReaction = ($activityId === self::ACTIVITY_REACTION);
            $bestStructure = $this->bonusService->findBestStructureForProduct($user, $productTypeId, $isReaction);
            $structureBonus = $bestStructure['bonus'];
            $structureName = $bestStructure['structure']?->getName();
            $productCategory = $bestStructure['category'];
        }

        // Get materials for this activity
        $materials = $this->activityMaterialRepository->findBy([
            'typeId' => $blueprintTypeId,
            'activityId' => $activityId,
        ]);

        // ME only applies to manufacturing, not reactions
        // Structure bonus applies to both manufacturing and reactions
        $applyMe = ($activityId === self::ACTIVITY_MANUFACTURING);
        $effectiveMe = $applyMe ? $meLevel : 0;

        $materialNodes = [];
        foreach ($materials as $material) {
            $matTypeId = $material->getMaterialTypeId();
            $baseQuantity = $material->getQuantity();

            // Calculate material reduction
            // Formula: base * runs * (1 - ME/100) * (1 - structureBonus/100)
            $meMultiplier = $applyMe && $effectiveMe > 0 ? (1 - $effectiveMe / 100) : 1.0;
            $structureMultiplier = $structureBonus > 0 ? (1 - $structureBonus / 100) : 1.0;

            $adjustedQuantity = max(
                $runs,
                (int) ceil(round($baseQuantity * $runs * $meMultiplier * $structureMultiplier, 2))
            );

            $matType = $this->invTypeRepository->find($matTypeId);
            $matTypeName = $matType?->getTypeName() ?? "Type #{$matTypeId}";

            // Check if this material can be produced (manufacturing or reaction)
            // Excluded types are treated as raw materials (not expanded)
            $isExcluded = in_array($matTypeId, $excludedTypeIds, true);
            $matProducer = $isExcluded ? null : $this->findProducerFor($matTypeId);
            $isBuildable = $matProducer !== null;
            $matActivityType = null;
            if ($isBuildable) {
                $matActivityType = match ($matProducer->getActivityId()) {
                    self::ACTIVITY_REACTION => 'reaction',
                    default => 'manufacturing',
                };
            }

            $node = [
                'typeId' => $matTypeId,
                'typeName' => $matTypeName,
                'quantity' => $adjustedQuantity,
                'isBuildable' => $isBuildable,
                'activityType' => $matActivityType,
            ];

            if ($isBuildable) {
                // Intermediate manufacturing uses ME 10, reactions have no ME
                $childMe = ($matProducer->getActivityId() === self::ACTIVITY_MANUFACTURING) ? 10 : 0;
                $node['blueprint'] = $this->buildNode($matTypeId, $adjustedQuantity, $childMe, $depth + 1, $excludedTypeIds, $user);
            }

            $materialNodes[] = $node;
        }

        // Check if this blueprint supports copying (for BPC step)
        $hasCopy = $this->hasCopyActivity($blueprintTypeId);

        return [
            'blueprintTypeId' => $blueprintTypeId,
            'productTypeId' => $productTypeId,
            'productTypeName' => $productTypeName,
            'quantity' => $quantity,
            'runs' => $runs,
            'outputPerRun' => $outputPerRun,
            'depth' => $depth,
            'activityType' => $activityType,
            'hasCopy' => $hasCopy,
            'materials' => $materialNodes,
            // Structure bonus info
            'structureBonus' => $structureBonus,
            'structureName' => $structureName,
            'productCategory' => $productCategory,
        ];
    }

    /**
     * Find a blueprint or reaction formula that produces the given type.
     * Prefers manufacturing (1), falls back to reaction (11).
     */
    private function findProducerFor(int $productTypeId): ?\App\Entity\Sde\IndustryActivityProduct
    {
        // Try manufacturing first
        $product = $this->activityProductRepository->findBlueprintForProduct($productTypeId, self::ACTIVITY_MANUFACTURING);
        if ($product !== null) {
            return $product;
        }

        // Try reaction
        return $this->activityProductRepository->findBlueprintForProduct($productTypeId, self::ACTIVITY_REACTION);
    }

    /**
     * Check if a blueprint has a copying activity (activityId=5).
     */
    private function hasCopyActivity(int $blueprintTypeId): bool
    {
        $conn = $this->entityManager->getConnection();
        $result = $conn->fetchOne(
            'SELECT COUNT(*) FROM sde_industry_activities WHERE type_id = ? AND activity_id = ?',
            [$blueprintTypeId, self::ACTIVITY_COPYING],
        );

        return (int) $result > 0;
    }
}
