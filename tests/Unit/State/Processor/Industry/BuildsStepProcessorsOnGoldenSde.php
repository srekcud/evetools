<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\Industry;

use App\Entity\Sde\IndustryActivityProduct;
use App\Repository\IndustryRigCategoryRepository;
use App\Repository\IndustryStructureConfigRepository;
use App\Repository\Sde\IndustryActivityProductRepository;
use App\Repository\Sde\InvTypeRepository;
use App\Service\Industry\IndustryStepCalculator;
use App\Tests\Unit\Service\Industry\BuildsProjectOnGoldenSde;
use App\Tests\Unit\Service\Industry\TataraAndRaitaruBonusService;
use Doctrine\ORM\EntityManagerInterface;

trait BuildsStepProcessorsOnGoldenSde
{
    use BuildsProjectOnGoldenSde;

    private function stepCalculatorOnGoldenSde(IndustryActivityProductRepository $productRepository, IndustryStructureConfigRepository $structureConfigRepository, EntityManagerInterface $entityManager): IndustryStepCalculator
    {
        return new IndustryStepCalculator(
            $this->calculationServiceOnGoldenSde($this->bonusServiceWithoutStructure(), $structureConfigRepository),
            $this->materialRepositoryOnGoldenSde(),
            $productRepository,
            $entityManager,
        );
    }

    private function productRepositoryOfTheGoldenBlueprints(): IndustryActivityProductRepository
    {
        $productOf = static function (int $blueprintTypeId, int $activityId): ?IndustryActivityProduct {
            $productTypeId = $blueprintTypeId - self::SYNTHETIC_BLUEPRINT_TYPE_ID_OFFSET;
            $recipe = self::goldenRecipes()[$productTypeId] ?? null;
            if ($recipe === null || self::activityIdOf($recipe['activity']) !== $activityId) {
                return null;
            }

            return self::activityProduct($productTypeId, $recipe);
        };
        $repository = $this->createStub(IndustryActivityProductRepository::class);
        $repository->method('findOneBy')->willReturnCallback(
            static fn (array $criteria): ?IndustryActivityProduct => $productOf($criteria['typeId'], $criteria['activityId']),
        );
        $repository->method('findProductsForBlueprints')->willReturnCallback(
            static function (array $blueprintTypeIds, array $activityIds) use ($productOf): array {
                $products = [];
                foreach (array_unique($blueprintTypeIds) as $blueprintTypeId) {
                    foreach (array_unique($activityIds) as $activityId) {
                        $product = $productOf($blueprintTypeId, $activityId);
                        if ($product !== null) {
                            $products[$blueprintTypeId.'-'.$activityId] = $product;
                        }
                    }
                }

                return $products;
            },
        );

        return $repository;
    }

    private function bonusServiceWithoutStructure(): TataraAndRaitaruBonusService
    {
        return new TataraAndRaitaruBonusService(
            static fn (): bool => false,
            static fn (int $typeId): bool => (self::goldenRecipes()[$typeId]['activity'] ?? null) === 'reaction',
            $this->createStub(IndustryRigCategoryRepository::class),
            $this->createStub(IndustryStructureConfigRepository::class),
            $this->createStub(InvTypeRepository::class),
            $this->createStub(IndustryActivityProductRepository::class),
        );
    }
}
