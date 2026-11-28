<?php

declare(strict_types=1);

namespace App\Industry\Infrastructure\Sde;

use App\Industry\Application\InventionCatalog;
use App\Industry\Application\InventionRecipe;
use App\Industry\Domain\Quantity;
use App\Industry\Domain\RecipeMaterial;
use App\Industry\Domain\Runs;
use App\Repository\Sde\IndustryActivityMaterialRepository;
use App\Repository\Sde\IndustryActivityProductRepository;

final readonly class SdeInventionCatalog implements InventionCatalog
{
    private const int ACTIVITY_MANUFACTURING = 1;
    private const int ACTIVITY_INVENTION = 8;
    private const int RELICS_CONSUMED_PER_ATTEMPT = 1;

    public function __construct(
        private IndustryActivityProductRepository $activityProductRepository,
        private IndustryActivityMaterialRepository $activityMaterialRepository,
    ) {
    }

    public function inventionOf(int $t2ProductTypeId): ?InventionRecipe
    {
        $t2Manufacturing = $this->activityProductRepository->findBlueprintForProduct($t2ProductTypeId, self::ACTIVITY_MANUFACTURING);
        if (null === $t2Manufacturing) {
            return null;
        }

        $invention = $this->activityProductRepository->findBlueprintForProduct($t2Manufacturing->getTypeId(), self::ACTIVITY_INVENTION);
        if (null === $invention) {
            return null;
        }

        $t1BlueprintTypeId = $invention->getTypeId();
        $t1Manufacturing = $this->activityProductRepository->findOneBy(['typeId' => $t1BlueprintTypeId, 'activityId' => self::ACTIVITY_MANUFACTURING]);

        $datacores = [];
        foreach ($this->activityMaterialRepository->findByBlueprintAndActivity($t1BlueprintTypeId, self::ACTIVITY_INVENTION) as $datacore) {
            $datacores[] = new RecipeMaterial($datacore->getMaterialTypeId(), new Quantity($datacore->getQuantity()));
        }
        if (null === $t1Manufacturing) {
            $datacores[] = new RecipeMaterial($t1BlueprintTypeId, new Quantity(self::RELICS_CONSUMED_PER_ATTEMPT));
        }

        return new InventionRecipe(
            $t1BlueprintTypeId,
            $t1Manufacturing?->getProductTypeId(),
            $invention->getProbability(),
            new Runs($invention->getQuantity()),
            $datacores,
        );
    }
}
