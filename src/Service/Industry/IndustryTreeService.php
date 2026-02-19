<?php

declare(strict_types=1);

namespace App\Service\Industry;

use App\Entity\User;
use App\Repository\Sde\IndustryActivityMaterialRepository;
use App\Repository\Sde\IndustryActivityProductRepository;
use App\Enum\IndustryActivityType;
use App\Repository\Sde\InvTypeRepository;
use Doctrine\ORM\EntityManagerInterface;

class IndustryTreeService
{

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
     * @param list<int> $excludedTypeIds Type IDs to treat as raw materials (not expanded)
     * @param User|null $user User for structure bonus calculation (optional)
     * @param array<int, float> $structureBonusOverrides Map of productTypeId => materialBonus to override best-structure lookup
     * @return array<string, mixed>
     */
    public function buildProductionTree(int $productTypeId, int $runs = 1, int $finalMe = 0, array $excludedTypeIds = [], ?User $user = null, array $structureBonusOverrides = []): array
    {
        return $this->buildNode($productTypeId, $runs, $finalMe, 0, $excludedTypeIds, $user, true, $structureBonusOverrides);
    }

    /**
     * @param list<int> $excludedTypeIds
     * @param array<int, float> $structureBonusOverrides
     * @return array<string, mixed>
     */
    private function buildNode(int $productTypeId, int $quantity, int $meLevel, int $depth, array $excludedTypeIds, ?User $user, bool $isRoot = false, array $structureBonusOverrides = []): array
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
            IndustryActivityType::Reaction->value => 'reaction',
            IndustryActivityType::Copying->value => 'copy',
            default => 'manufacturing',
        };

        // Get structure bonus for this product (separate base and rig for multiplicative stacking)
        $structureBaseBonus = 0.0;
        $rigBonus = 0.0;
        $structureBonusTotal = 0.0;
        $structureName = null;
        $productCategory = null;
        $hasOverride = isset($structureBonusOverrides[$productTypeId]);

        if ($hasOverride) {
            // Override provides a single total value (used for suboptimal comparison)
            $structureBonusTotal = $structureBonusOverrides[$productTypeId];
            // For overrides, treat as a single combined value (backward compat for shopping list delta)
            $structureBaseBonus = $structureBonusTotal;
            $rigBonus = 0.0;
            if ($user !== null) {
                $isReaction = ($activityId === IndustryActivityType::Reaction->value);
                $productCategory = $this->bonusService->getCategoryForProduct($productTypeId, $isReaction);
            }
        } elseif ($user !== null) {
            $isReaction = ($activityId === IndustryActivityType::Reaction->value);
            $bestStructure = $this->bonusService->findBestStructureForProduct($user, $productTypeId, $isReaction);
            $structureBaseBonus = $bestStructure['bonus']['base'];
            $rigBonus = $bestStructure['bonus']['rig'];
            $structureBonusTotal = $bestStructure['bonus']['total'];
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
        $applyMe = ($activityId === IndustryActivityType::Manufacturing->value);
        $effectiveMe = $applyMe ? $meLevel : 0;

        $materialNodes = [];
        foreach ($materials as $material) {
            $matTypeId = $material->getMaterialTypeId();
            $baseQuantity = $material->getQuantity();

            // Calculate material reduction
            // Formula: base * runs * (1 - ME/100) * (1 - structureBase/100) * (1 - rigBonus/100)
            // IMPORTANT: The structure bonus must be looked up for EACH material's category,
            // not the parent product's category. E.g., when building a Rorqual (capital_ship),
            // the CCP materials are basic_capital_component and need their own bonus.
            // When an override is active for this node, use a single bonus (matching recalculateStepQuantities behavior).
            $matStructureBase = $structureBaseBonus;
            $matRigBonus = $rigBonus;
            if (!$hasOverride && $user !== null) {
                $materialCategory = $this->bonusService->getCategoryForProduct($matTypeId, false);
                if ($materialCategory !== null && $materialCategory !== $productCategory) {
                    $materialBonusData = $this->bonusService->findBestStructureForCategory($user, $materialCategory, false);
                    $matStructureBase = $materialBonusData['bonus']['base'];
                    $matRigBonus = $materialBonusData['bonus']['rig'];
                }
            }

            $meMultiplier = $applyMe && $effectiveMe > 0 ? (1 - $effectiveMe / 100) : 1.0;
            $structureMultiplier = $matStructureBase > 0 ? (1 - $matStructureBase / 100) : 1.0;
            $rigMultiplier = $matRigBonus > 0 ? (1 - $matRigBonus / 100) : 1.0;

            $adjustedQuantity = max(
                $runs,
                (int) ceil(round($baseQuantity * $runs * $meMultiplier * $structureMultiplier * $rigMultiplier, 2))
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
                    IndustryActivityType::Reaction->value => 'reaction',
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
                $childMe = ($matProducer->getActivityId() === IndustryActivityType::Manufacturing->value) ? 10 : 0;
                $node['blueprint'] = $this->buildNode($matTypeId, $adjustedQuantity, $childMe, $depth + 1, $excludedTypeIds, $user, false, $structureBonusOverrides);
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
            'structureBonus' => $structureBonusTotal,
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
        $product = $this->activityProductRepository->findBlueprintForProduct($productTypeId, IndustryActivityType::Manufacturing->value);
        if ($product !== null) {
            return $product;
        }

        // Try reaction
        return $this->activityProductRepository->findBlueprintForProduct($productTypeId, IndustryActivityType::Reaction->value);
    }

    /**
     * Check if a blueprint has a copying activity (activityId=5).
     */
    private function hasCopyActivity(int $blueprintTypeId): bool
    {
        $conn = $this->entityManager->getConnection();
        $result = $conn->fetchOne(
            'SELECT COUNT(*) FROM sde_industry_activities WHERE type_id = ? AND activity_id = ?',
            [$blueprintTypeId, IndustryActivityType::Copying->value],
        );

        return (int) $result > 0;
    }
}
