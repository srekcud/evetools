<?php

declare(strict_types=1);

namespace App\Industry\Infrastructure\Sde;

use App\Industry\Application\RigCategories;
use App\Industry\Domain\ActivityKind;
use App\Repository\IndustryRigCategoryRepository;
use App\Repository\Sde\IndustryActivityProductRepository;
use App\Repository\Sde\InvTypeRepository;

final class SdeRigCategories implements RigCategories
{
    private const int ACTIVITY_REACTION = 11;

    /** @var array<int, string>|null */
    private ?array $categoriesByGroup = null;

    public function __construct(
        private readonly IndustryRigCategoryRepository $rigCategoryRepository,
        private readonly IndustryActivityProductRepository $activityProductRepository,
        private readonly InvTypeRepository $invTypeRepository,
    ) {
    }

    public function categoryOf(int $productTypeId, ActivityKind $activity): ?string
    {
        $groupId = $this->groupOf(ActivityKind::Reaction === $activity ? $this->formulaOf($productTypeId) : $productTypeId);
        $this->categoriesByGroup ??= $this->rigCategoryRepository->buildGroupCategoryMap();

        return $this->categoriesByGroup[$groupId] ?? null;
    }

    private function formulaOf(int $productTypeId): int
    {
        $formula = $this->activityProductRepository->findBlueprintForProduct($productTypeId, self::ACTIVITY_REACTION)
            ?? throw new \UnexpectedValueException(\sprintf('Reaction product %d has no formula in the SDE.', $productTypeId));

        return $formula->getTypeId();
    }

    private function groupOf(int $typeId): int
    {
        $type = $this->invTypeRepository->find($typeId)
            ?? throw new \UnexpectedValueException(\sprintf('Type %d is missing from the SDE.', $typeId));

        return $type->getGroup()->getGroupId();
    }
}
