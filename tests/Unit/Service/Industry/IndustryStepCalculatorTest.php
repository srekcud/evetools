<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectStep;
use App\Entity\Sde\IndustryActivityProduct;
use App\Enum\IndustryActivityType;
use App\Repository\Sde\IndustryActivityMaterialRepository;
use App\Repository\Sde\IndustryActivityProductRepository;
use App\Service\Industry\IndustryCalculationService;
use App\Service\Industry\IndustryStepCalculator;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(IndustryStepCalculator::class)]
class IndustryStepCalculatorTest extends TestCase
{
    private IndustryCalculationService&MockObject $calculationService;
    private IndustryActivityMaterialRepository&MockObject $materialRepository;
    private IndustryActivityProductRepository&MockObject $productRepository;
    private EntityManagerInterface&MockObject $entityManager;
    private IndustryStepCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculationService = $this->createMock(IndustryCalculationService::class);
        $this->materialRepository = $this->createMock(IndustryActivityMaterialRepository::class);
        $this->productRepository = $this->createMock(IndustryActivityProductRepository::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);

        $this->calculator = new IndustryStepCalculator(
            $this->calculationService,
            $this->materialRepository,
            $this->productRepository,
            $this->entityManager,
        );
    }

    // ===========================================
    // Helper methods
    // ===========================================

    private function createStep(
        string $activityType,
        int $blueprintTypeId,
        int $productTypeId,
        int $runs,
        int $quantity,
        int $depth,
        int $meLevel = 10,
        ?string $splitGroupId = null,
        int $splitIndex = 0,
    ): IndustryProjectStep {
        $step = new IndustryProjectStep();
        $step->setActivityType($activityType);
        $step->setBlueprintTypeId($blueprintTypeId);
        $step->setProductTypeId($productTypeId);
        $step->setRuns($runs);
        $step->setQuantity($quantity);
        $step->setDepth($depth);
        $step->setMeLevel($meLevel);
        $step->setSplitGroupId($splitGroupId);
        $step->setSplitIndex($splitIndex);

        return $step;
    }

    private function createProject(array $steps): IndustryProject
    {
        $project = new IndustryProject();
        foreach ($steps as $step) {
            $project->addStep($step);
        }

        return $project;
    }

    // ===========================================
    // recalculateStepQuantities() - basic
    // ===========================================

    public function testRecalculateQuantitiesBasicParentChild(): void
    {
        // Parent at depth 0: blueprint 100 produces product 101, needs material 201 (child product)
        // Child at depth 1: blueprint 200 produces product 201
        $parentStep = $this->createStep('manufacturing', 100, 101, 10, 10, 0);
        $childStep = $this->createStep('manufacturing', 200, 201, 50, 50, 1);

        $project = $this->createProject([$parentStep, $childStep]);

        // Parent's blueprint requires child's product as material (batch preload)
        $mat = new \App\Entity\Sde\IndustryActivityMaterial();
        $mat->setTypeId(100);
        $mat->setActivityId(IndustryActivityType::Manufacturing->value);
        $mat->setMaterialTypeId(201);
        $mat->setQuantity(5); // 5 per run

        $this->materialRepository->method('findMaterialEntitiesForBlueprints')
            ->willReturn([
                '100-1' => [$mat],
            ]);

        // Structure bonus: no bonus
        $this->calculationService->method('getStructureBonusForStep')
            ->willReturn([
                'structure' => null,
                'materialBonus' => ['total' => 0.0, 'base' => 0.0, 'rig' => 0.0],
                'timeBonus' => 0.0,
                'name' => null,
            ]);

        // calculateMaterialQuantity: 5 base * 10 runs * ME=10 reduction
        // With ME 10: max(10, ceil(round(5 * 10 * 0.9, 2))) = max(10, 45) = 45
        $this->calculationService->method('calculateMaterialQuantity')
            ->with(5, 10, 10, 0.0, 0.0)
            ->willReturn(45);

        // Child output per run: 1 (batch preload)
        $childProduct = new IndustryActivityProduct();
        $childProduct->setTypeId(200);
        $childProduct->setActivityId(IndustryActivityType::Manufacturing->value);
        $childProduct->setProductTypeId(201);
        $childProduct->setQuantity(1);
        $this->productRepository->method('findProductsForBlueprints')
            ->willReturn([
                '200-1' => $childProduct,
            ]);

        $this->entityManager->expects($this->once())->method('flush');

        $updated = $this->calculator->recalculateStepQuantities($project);

        $this->assertCount(1, $updated);
        $this->assertSame(201, $updated[0]->getProductTypeId());
        $this->assertSame(45, $updated[0]->getQuantity());
        $this->assertSame(45, $updated[0]->getRuns());
    }

    public function testRecalculateQuantitiesEmptyProject(): void
    {
        $project = $this->createProject([]);

        $updated = $this->calculator->recalculateStepQuantities($project);

        $this->assertCount(0, $updated);
    }

    public function testRecalculateQuantitiesWithNoChildChanges(): void
    {
        // If child already has correct quantity, no update should happen
        $parentStep = $this->createStep('manufacturing', 100, 101, 10, 10, 0);
        $childStep = $this->createStep('manufacturing', 200, 201, 45, 45, 1);

        $project = $this->createProject([$parentStep, $childStep]);

        $mat = new \App\Entity\Sde\IndustryActivityMaterial();
        $mat->setTypeId(100);
        $mat->setActivityId(IndustryActivityType::Manufacturing->value);
        $mat->setMaterialTypeId(201);
        $mat->setQuantity(5);

        $this->materialRepository->method('findMaterialEntitiesForBlueprints')
            ->willReturn([
                '100-1' => [$mat],
            ]);

        $this->calculationService->method('getStructureBonusForStep')
            ->willReturn([
                'structure' => null,
                'materialBonus' => ['total' => 0.0, 'base' => 0.0, 'rig' => 0.0],
                'timeBonus' => 0.0,
                'name' => null,
            ]);

        $this->calculationService->method('calculateMaterialQuantity')
            ->willReturn(45);

        $childProduct = new IndustryActivityProduct();
        $childProduct->setTypeId(200);
        $childProduct->setActivityId(IndustryActivityType::Manufacturing->value);
        $childProduct->setProductTypeId(201);
        $childProduct->setQuantity(1);
        $this->productRepository->method('findProductsForBlueprints')
            ->willReturn([
                '200-1' => $childProduct,
            ]);

        // No flush expected because nothing changed
        $this->entityManager->expects($this->never())->method('flush');

        $updated = $this->calculator->recalculateStepQuantities($project);

        $this->assertCount(0, $updated);
    }

    // ===========================================
    // Split group redistribution
    // ===========================================

    public function testRedistributeAfterSplitProportionally(): void
    {
        // Parent at depth 0 needs 100 of product 201
        $parentStep = $this->createStep('manufacturing', 100, 101, 10, 10, 0);

        // Child split into 2 parts: 30 runs + 20 runs = 50 total
        $splitGroupId = 'split-group-1';
        $child1 = $this->createStep('manufacturing', 200, 201, 30, 30, 1, 10, $splitGroupId, 0);
        $child2 = $this->createStep('manufacturing', 200, 201, 20, 20, 1, 10, $splitGroupId, 1);

        $project = $this->createProject([$parentStep, $child1, $child2]);

        $mat = new \App\Entity\Sde\IndustryActivityMaterial();
        $mat->setTypeId(100);
        $mat->setActivityId(IndustryActivityType::Manufacturing->value);
        $mat->setMaterialTypeId(201);
        $mat->setQuantity(10);

        $this->materialRepository->method('findMaterialEntitiesForBlueprints')
            ->willReturn([
                '100-1' => [$mat],
            ]);

        $this->calculationService->method('getStructureBonusForStep')
            ->willReturn([
                'structure' => null,
                'materialBonus' => ['total' => 0.0, 'base' => 0.0, 'rig' => 0.0],
                'timeBonus' => 0.0,
                'name' => null,
            ]);

        // 10 base * 10 runs with ME10 = 90
        $this->calculationService->method('calculateMaterialQuantity')
            ->willReturn(90);

        $childProduct = new IndustryActivityProduct();
        $childProduct->setTypeId(200);
        $childProduct->setActivityId(IndustryActivityType::Manufacturing->value);
        $childProduct->setProductTypeId(201);
        $childProduct->setQuantity(1);
        $this->productRepository->method('findProductsForBlueprints')
            ->willReturn([
                '200-1' => $childProduct,
            ]);

        $this->entityManager->expects($this->once())->method('flush');

        $updated = $this->calculator->recalculateStepQuantities($project);

        // Both children should be updated
        $this->assertGreaterThanOrEqual(2, count($updated));

        // Total runs across splits should equal 90
        $totalRuns = $child1->getRuns() + $child2->getRuns();
        $this->assertSame(90, $totalRuns);

        // Child1 had 30/50 = 60% of old runs, so ~54 of 90
        // Child2 had 20/50 = 40% of old runs, so the remainder
        // Due to rounding: child1 = round(90 * 30/50) = 54, child2 = 90 - 54 = 36
        $this->assertSame(54, $child1->getRuns());
        $this->assertSame(36, $child2->getRuns());
    }

    // ===========================================
    // Edge cases
    // ===========================================

    public function testCopyStepsAreSkippedInLookup(): void
    {
        // A copy step should be ignored (not indexed by productTypeId)
        $parentStep = $this->createStep('manufacturing', 100, 101, 10, 10, 0);
        $copyStep = $this->createStep('copy', 100, 101, 10, 10, 0);

        $project = $this->createProject([$parentStep, $copyStep]);

        // No materials to process
        $this->materialRepository->method('findMaterialEntitiesForBlueprints')->willReturn([]);
        $this->productRepository->method('findProductsForBlueprints')->willReturn([]);
        $this->calculationService->method('getStructureBonusForStep')
            ->willReturn([
                'structure' => null,
                'materialBonus' => ['total' => 0.0, 'base' => 0.0, 'rig' => 0.0],
                'timeBonus' => 0.0,
                'name' => null,
            ]);

        $updated = $this->calculator->recalculateStepQuantities($project);

        $this->assertCount(0, $updated);
    }

    public function testReactionStepUsesReactionActivityId(): void
    {
        $parentStep = $this->createStep('reaction', 300, 301, 5, 1000, 0, 0);
        $childStep = $this->createStep('reaction', 400, 401, 10, 2000, 1, 0);

        $project = $this->createProject([$parentStep, $childStep]);

        // Parent is reaction, so activity ID should be 11
        $mat = new \App\Entity\Sde\IndustryActivityMaterial();
        $mat->setTypeId(300);
        $mat->setActivityId(IndustryActivityType::Reaction->value);
        $mat->setMaterialTypeId(401);
        $mat->setQuantity(100);

        $this->materialRepository->method('findMaterialEntitiesForBlueprints')
            ->willReturn([
                '300-11' => [$mat],
            ]);

        $this->calculationService->method('getStructureBonusForStep')
            ->willReturn([
                'structure' => null,
                'materialBonus' => ['total' => 0.0, 'base' => 0.0, 'rig' => 0.0],
                'timeBonus' => 0.0,
                'name' => null,
            ]);

        // Reaction with ME=0: 100 * 5 = 500
        $this->calculationService->method('calculateMaterialQuantity')
            ->willReturn(500);

        $childProduct = new IndustryActivityProduct();
        $childProduct->setTypeId(400);
        $childProduct->setActivityId(IndustryActivityType::Reaction->value);
        $childProduct->setProductTypeId(401);
        $childProduct->setQuantity(200); // 200 per run
        $this->productRepository->method('findProductsForBlueprints')
            ->willReturn([
                '400-11' => $childProduct,
            ]);

        $this->entityManager->expects($this->once())->method('flush');

        $updated = $this->calculator->recalculateStepQuantities($project);

        $this->assertCount(1, $updated);
        // 500 needed / 200 per run = ceil(2.5) = 3 runs
        $this->assertSame(3, $childStep->getRuns());
        $this->assertSame(500, $childStep->getQuantity());
    }

    public function testSplitGroupWithZeroOldTotalRunsUsesOneAsDefault(): void
    {
        // Edge case: old total runs = 0 (shouldn't happen, but guard against division by zero)
        $parentStep = $this->createStep('manufacturing', 100, 101, 10, 10, 0);

        $splitGroupId = 'split-zero';
        $child1 = $this->createStep('manufacturing', 200, 201, 0, 0, 1, 10, $splitGroupId, 0);
        $child2 = $this->createStep('manufacturing', 200, 201, 0, 0, 1, 10, $splitGroupId, 1);

        $project = $this->createProject([$parentStep, $child1, $child2]);

        $mat = new \App\Entity\Sde\IndustryActivityMaterial();
        $mat->setTypeId(100);
        $mat->setActivityId(IndustryActivityType::Manufacturing->value);
        $mat->setMaterialTypeId(201);
        $mat->setQuantity(5);

        $this->materialRepository->method('findMaterialEntitiesForBlueprints')
            ->willReturn([
                '100-1' => [$mat],
            ]);

        $this->calculationService->method('getStructureBonusForStep')
            ->willReturn([
                'structure' => null,
                'materialBonus' => ['total' => 0.0, 'base' => 0.0, 'rig' => 0.0],
                'timeBonus' => 0.0,
                'name' => null,
            ]);

        $this->calculationService->method('calculateMaterialQuantity')
            ->willReturn(45);

        $childProduct = new IndustryActivityProduct();
        $childProduct->setTypeId(200);
        $childProduct->setActivityId(IndustryActivityType::Manufacturing->value);
        $childProduct->setProductTypeId(201);
        $childProduct->setQuantity(1);
        $this->productRepository->method('findProductsForBlueprints')
            ->willReturn([
                '200-1' => $childProduct,
            ]);

        $this->entityManager->expects($this->once())->method('flush');

        // Should not throw, and should redistribute with oldTotalRuns defaulting to 1
        $updated = $this->calculator->recalculateStepQuantities($project);

        $this->assertGreaterThanOrEqual(2, count($updated));

        // Both children should have at least 1 run
        $this->assertGreaterThanOrEqual(1, $child1->getRuns());
        $this->assertGreaterThanOrEqual(1, $child2->getRuns());
    }
}
