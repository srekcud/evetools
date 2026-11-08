<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

use App\Industry\Domain\LineRentalCategory;
use App\Repository\IndustryRigCategoryRepository;
use App\Repository\Sde\InvTypeRepository;
use App\Service\Industry\InventionService;

class LineRentalCategoryResolver
{
    private const int SHIP_CATEGORY_ID = 6;
    private const int MODULE_CATEGORY_ID = 7;
    private const int CONSTRUCTION_COMPONENTS_GROUP_ID = 334;
    private const int CAPITAL_CONSTRUCTION_COMPONENTS_GROUP_ID = 873;
    private const string CAPITAL_SHIP_RIG_CATEGORY = 'capital_ship';

    public function __construct(
        private readonly InvTypeRepository $invTypeRepository,
        private readonly IndustryRigCategoryRepository $rigCategoryRepository,
        private readonly InventionService $inventionService,
    ) {
    }

    public function categoryOf(int $productTypeId): ?LineRentalCategory
    {
        $type = $this->invTypeRepository->findByTypeId($productTypeId);
        if ($type === null) {
            return null;
        }

        $groupId = $type->getGroup()->getGroupId();
        $categoryId = $type->getGroup()->getCategory()->getCategoryId();

        if ($categoryId === self::SHIP_CATEGORY_ID) {
            return $this->shipCategory($productTypeId, $groupId);
        }

        if ($categoryId === self::MODULE_CATEGORY_ID) {
            return $this->inventionService->isT2($productTypeId) ? LineRentalCategory::ModuleT2 : LineRentalCategory::ModuleT1;
        }

        if ($groupId === self::CONSTRUCTION_COMPONENTS_GROUP_ID || $groupId === self::CAPITAL_CONSTRUCTION_COMPONENTS_GROUP_ID) {
            return LineRentalCategory::ComponentT1;
        }

        return null;
    }

    private function shipCategory(int $productTypeId, int $groupId): LineRentalCategory
    {
        $isT2 = $this->inventionService->isT2($productTypeId);
        $rigCategoryByGroupId = $this->rigCategoryRepository->buildGroupCategoryMap();
        $isCapital = array_key_exists($groupId, $rigCategoryByGroupId)
            && $rigCategoryByGroupId[$groupId] === self::CAPITAL_SHIP_RIG_CATEGORY;

        if ($isCapital) {
            return $isT2 ? LineRentalCategory::CapitalT2 : LineRentalCategory::CapitalT1;
        }

        return $isT2 ? LineRentalCategory::ShipT2 : LineRentalCategory::ShipT1;
    }
}
