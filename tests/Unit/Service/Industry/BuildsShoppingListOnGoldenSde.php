<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectStep;
use App\Entity\IndustryStructureConfig;
use App\Entity\User;
use App\Repository\IndustryRigCategoryRepository;
use App\Repository\IndustryStructureConfigRepository;
use App\Service\Industry\IndustryBlacklistService;
use App\Service\Industry\IndustryBonusService;
use App\Service\Industry\IndustryShoppingListBuilder;
use App\Service\Industry\IndustryStepCalculator;
use App\Service\Industry\IndustryTreeService;
use App\Service\TypeNameResolver;

trait BuildsShoppingListOnGoldenSde
{
    use BuildsProjectOnGoldenSde;

    private array $pilotStructures = [];

    private function shoppingListBuilderOnGoldenSde(): IndustryShoppingListBuilder
    {
        $typeRepository = $this->typeRepositoryOnGoldenSde();
        $productRepository = $this->productRepositoryOnGoldenSde();
        $materialRepository = $this->materialRepositoryOnGoldenSde();
        $entityManager = $this->entityManagerWithoutRows();
        $bonusService = $this->pilotBonusService();
        $calculationService = $this->calculationServiceOnGoldenSde($bonusService, $this->pilotStructureConfigRepository());

        return self::autowired(IndustryShoppingListBuilder::class, [
            new IndustryTreeService($productRepository, $materialRepository, $typeRepository, $entityManager, $bonusService),
            new IndustryBlacklistService($entityManager),
            $bonusService,
            $calculationService,
            new IndustryStepCalculator($calculationService, $materialRepository, $productRepository, $entityManager),
            new TypeNameResolver($typeRepository),
            $typeRepository,
            $productRepository,
            $materialRepository,
            $this->activityRepositoryOnGoldenSde(),
            $entityManager,
        ]);
    }

    private function projectOnGoldenSde(User $pilot, int $productTypeId, int $runs, int $meLevel, int $teLevel, float $maxJobDurationDays): IndustryProject
    {
        $bonusService = $this->pilotBonusService();

        return $this->projectFactoryOnGoldenSde($bonusService, $this->calculationServiceOnGoldenSde($bonusService, $this->pilotStructureConfigRepository()))
            ->createProject($pilot, $productTypeId, $runs, $meLevel, $maxJobDurationDays, $teLevel);
    }

    private function pilotBonusService(): IndustryBonusService
    {
        $rigCategoryRepository = $this->createStub(IndustryRigCategoryRepository::class);
        $rigCategoryRepository->method('buildGroupCategoryMap')->willReturn([]);

        return new IndustryBonusService($rigCategoryRepository, $this->pilotStructureConfigRepository(), $this->typeRepositoryOnGoldenSde(), $this->productRepositoryOnGoldenSde());
    }

    private function pilotStructureConfigRepository(): IndustryStructureConfigRepository
    {
        $structureConfigRepository = $this->createStub(IndustryStructureConfigRepository::class);
        $structureConfigRepository->method('findByUser')->willReturnCallback(fn (): array => $this->pilotStructures);

        return $structureConfigRepository;
    }

    private static function projectOf(User $pilot, int $productTypeId, int $runs, int $meLevel): IndustryProject
    {
        return (new IndustryProject())
            ->setUser($pilot)
            ->setProductTypeId($productTypeId)
            ->setRuns($runs)
            ->setMeLevel($meLevel);
    }

    private static function addStep(IndustryProject $project, int $productTypeId, int $runs, int $depth, string $activityType, int $meLevel = 10, ?int $quantity = null): IndustryProjectStep
    {
        $step = (new IndustryProjectStep())
            ->setProductTypeId($productTypeId)
            ->setBlueprintTypeId($productTypeId + self::SYNTHETIC_BLUEPRINT_TYPE_ID_OFFSET)
            ->setRuns($runs)
            ->setQuantity($quantity ?? $runs * (self::goldenRecipes()[$productTypeId]['outputPerRun'] ?? 1))
            ->setDepth($depth)
            ->setActivityType($activityType)
            ->setMeLevel($meLevel);
        $project->addStep($step);

        return $step;
    }

    private static function structure(User $pilot, string $structureType): IndustryStructureConfig
    {
        return (new IndustryStructureConfig())
            ->setUser($pilot)
            ->setName(ucfirst($structureType))
            ->setStructureType($structureType)
            ->setSecurityType('nullsec')
            ->setRigs([]);
    }

    private function stepsOf(IndustryProject $project, int $productTypeId): array
    {
        $steps = array_values(array_filter(
            $project->getSteps()->toArray(),
            static fn (IndustryProjectStep $step): bool => $step->getProductTypeId() === $productTypeId,
        ));
        usort($steps, static fn (IndustryProjectStep $a, IndustryProjectStep $b): int => $a->getSplitIndex() <=> $b->getSplitIndex());

        return $steps;
    }

    private function onlyStepOf(IndustryProject $project, int $productTypeId): IndustryProjectStep
    {
        $steps = $this->stepsOf($project, $productTypeId);
        self::assertCount(1, $steps);

        return $steps[0];
    }

    private static function quantitiesByTypeId(array $materials): array
    {
        $quantities = array_column($materials, 'quantity', 'typeId');
        ksort($quantities);

        return $quantities;
    }
}
