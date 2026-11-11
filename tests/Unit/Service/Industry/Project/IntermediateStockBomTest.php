<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;
use App\Service\Industry\Project\IndustryProjectService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(IndustryProjectService::class)]
#[AllowMockObjectsWithoutExpectations]
#[Group('issue-706')]
final class IntermediateStockBomTest extends TestCase
{
    use BuildsProjectServiceOnGoldenSde;

    private const int FERNITE_CARBIDE_UNITS_PER_RUN = 10_000;
    private const int PROJECT_MATERIAL_EFFICIENCY = 10;
    private const int PROJECT_TIME_EFFICIENCY = 20;

    private const array FERNITE_CARBIDE_ONE_RUN_LEAVES = [
        44 => 4, 3683 => 20, 3689 => 4, 9832 => 9, 9848 => 1, 16272 => 153, 16273 => 315, 16275 => 18,
        16635 => 100, 16636 => 100, 16639 => 100, 16642 => 100, 17889 => 405,
    ];

    private const array FERNITE_CARBIDE_OWN_LEAVES_OF_TEN_RUNS = [16635 => 500, 16636 => 500, 16639 => 500, 16642 => 500];

    private const array HYDROGEN_FUEL_BLOCK_TWO_RUNS_LEAVES = [
        44 => 8, 3683 => 40, 3689 => 8, 9832 => 17, 9848 => 2, 16272 => 306, 16273 => 630, 16275 => 36, 17889 => 810,
    ];

    protected function setUp(): void
    {
        $this->setUpGoldenSde();
    }

    public function testStockOfAnIntermediateBelowItsDemandReducesItsRunsAndTheLeavesUnderIt(): void
    {
        $project = $this->soloProjectOf([[self::FERNITE_CARBIDE, 10, self::PROJECT_MATERIAL_EFFICIENCY, self::PROJECT_TIME_EFFICIENCY]]);
        $this->projectService->buildBom($project, $this->owner);
        self::assertSame(
            [
                [self::HYDROGEN_FUEL_BLOCK, 'copy', 'blueprint', 3, 0, 0, 3],
                [self::HYDROGEN_FUEL_BLOCK, 'manufacturing', 'component', 3, 10, 20, 120],
                [self::FERNITE_ALLOY, 'reaction', 'component', 5, 0, 0, 1_000],
                [self::CERAMIC_POWDER, 'reaction', 'component', 5, 0, 0, 1_000],
                [self::FERNITE_CARBIDE, 'reaction', 'final', 10, 0, 0, 100_000],
            ],
            $this->jobs($project),
        );

        $this->approvedMaterialContribution($project, $this->lineOf($project, self::HYDROGEN_FUEL_BLOCK, 'manufacturing', 'component'), 30);
        $this->projectService->buildBom($project, $this->owner);

        self::assertSame(
            [
                [self::HYDROGEN_FUEL_BLOCK, 'copy', 'blueprint', 2, 0, 0, 2],
                [self::HYDROGEN_FUEL_BLOCK, 'manufacturing', 'component', 2, 10, 20, 110],
                [self::FERNITE_ALLOY, 'reaction', 'component', 5, 0, 0, 1_000],
                [self::CERAMIC_POWDER, 'reaction', 'component', 5, 0, 0, 1_000],
                [self::FERNITE_CARBIDE, 'reaction', 'final', 10, 0, 0, 100_000],
            ],
            $this->jobs($project),
        );
        $expectedLeaves = self::HYDROGEN_FUEL_BLOCK_TWO_RUNS_LEAVES + self::FERNITE_CARBIDE_OWN_LEAVES_OF_TEN_RUNS;
        ksort($expectedLeaves);
        self::assertSame($expectedLeaves, $this->materials($project));
    }

    public function testIntermediateLineKeepsItsStockInItsRequiredQuantityAndItsRemainingQuantityIsTheProductionLeft(): void
    {
        $project = $this->soloProjectOf([[self::FERNITE_CARBIDE, 10, self::PROJECT_MATERIAL_EFFICIENCY, self::PROJECT_TIME_EFFICIENCY]]);
        $this->projectService->buildBom($project, $this->owner);
        $hydrogenFuelBlock = $this->lineOf($project, self::HYDROGEN_FUEL_BLOCK, 'manufacturing', 'component');
        $this->approvedMaterialContribution($project, $hydrogenFuelBlock, 30);

        $this->projectService->buildBom($project, $this->owner);

        self::assertSame(
            [2, 110, 30, 80, 10],
            [$hydrogenFuelBlock->getRuns(), $hydrogenFuelBlock->getRequiredQuantity(), $hydrogenFuelBlock->getFulfilledQuantity(), $hydrogenFuelBlock->getRemainingQuantity(), $hydrogenFuelBlock->getSurplusQuantity()],
        );
    }

    public function testStockAboveTheDemandOfAnIntermediateKeepsItsLineAtZeroRunsWithoutLeavesOfItsOwn(): void
    {
        $project = $this->soloProjectOf([[self::FERNITE_CARBIDE, 1, self::PROJECT_MATERIAL_EFFICIENCY, self::PROJECT_TIME_EFFICIENCY]]);
        $this->projectService->buildBom($project, $this->owner);
        $hydrogenFuelBlock = $this->lineOf($project, self::HYDROGEN_FUEL_BLOCK, 'manufacturing', 'component');
        $this->approvedMaterialContribution($project, $hydrogenFuelBlock, 20);

        $this->projectService->buildBom($project, $this->owner);

        self::assertSame(
            [
                [self::HYDROGEN_FUEL_BLOCK, 'manufacturing', 'component', 0, 10, 20, 15],
                [self::FERNITE_ALLOY, 'reaction', 'component', 1, 0, 0, 200],
                [self::CERAMIC_POWDER, 'reaction', 'component', 1, 0, 0, 200],
                [self::FERNITE_CARBIDE, 'reaction', 'final', 1, 0, 0, self::FERNITE_CARBIDE_UNITS_PER_RUN],
            ],
            $this->jobs($project),
        );
        self::assertSame([16635 => 100, 16636 => 100, 16639 => 100, 16642 => 100], $this->materials($project));
        self::assertSame(
            [0, 15, 20, 0, null, null, 0],
            [$hydrogenFuelBlock->getRuns(), $hydrogenFuelBlock->getRequiredQuantity(), $hydrogenFuelBlock->getFulfilledQuantity(), $hydrogenFuelBlock->getRemainingQuantity(), $hydrogenFuelBlock->getPlannedStructureId(), $hydrogenFuelBlock->getPlannedStructureStatus(), $hydrogenFuelBlock->getSurplusQuantity()],
        );
    }

    public function testStockEqualToTheDemandOfAnIntermediateCoversItWithoutSurplus(): void
    {
        $project = $this->soloProjectOf([[self::FERNITE_CARBIDE, 1, self::PROJECT_MATERIAL_EFFICIENCY, self::PROJECT_TIME_EFFICIENCY]]);
        $this->projectService->buildBom($project, $this->owner);
        $hydrogenFuelBlock = $this->lineOf($project, self::HYDROGEN_FUEL_BLOCK, 'manufacturing', 'component');
        $this->approvedMaterialContribution($project, $hydrogenFuelBlock, 15);

        $this->projectService->buildBom($project, $this->owner);

        self::assertSame([0, 15, 15, 0, 0], [$hydrogenFuelBlock->getRuns(), $hydrogenFuelBlock->getRequiredQuantity(), $hydrogenFuelBlock->getFulfilledQuantity(), $hydrogenFuelBlock->getRemainingQuantity(), $hydrogenFuelBlock->getSurplusQuantity()]);
        self::assertSame([16635 => 100, 16636 => 100, 16639 => 100, 16642 => 100], $this->materials($project));
    }

    public function testFinalProductCoveredByItsStockKeepsItsLineAtZeroRunsAndNeedsNothingElse(): void
    {
        $project = $this->soloProjectOf([[self::FERNITE_CARBIDE, 1, self::PROJECT_MATERIAL_EFFICIENCY, self::PROJECT_TIME_EFFICIENCY]]);
        $this->projectService->buildBom($project, $this->owner);
        $finalLine = $this->lineOf($project, self::FERNITE_CARBIDE, 'reaction', 'final');
        $this->approvedMaterialContribution($project, $finalLine, self::FERNITE_CARBIDE_UNITS_PER_RUN);

        $this->projectService->buildBom($project, $this->owner);

        self::assertSame([[self::FERNITE_CARBIDE, 'reaction', 'final', 0, 0, 0, self::FERNITE_CARBIDE_UNITS_PER_RUN]], $this->jobs($project));
        self::assertSame([], $this->materials($project));
        self::assertSame(
            [0, self::FERNITE_CARBIDE_UNITS_PER_RUN, self::FERNITE_CARBIDE_UNITS_PER_RUN, 0, null, null],
            [$finalLine->getRuns(), $finalLine->getRequiredQuantity(), $finalLine->getFulfilledQuantity(), $finalLine->getRemainingQuantity(), $finalLine->getPlannedStructureId(), $finalLine->getPlannedStructureStatus()],
        );
    }

    public function testPlanOfAProjectWhoseOnlyProductIsCoveredByItsStockHasNoJobAndNoLeaf(): void
    {
        $project = $this->soloProjectOf([[self::FERNITE_CARBIDE, 1, self::PROJECT_MATERIAL_EFFICIENCY, self::PROJECT_TIME_EFFICIENCY]]);
        $this->projectService->buildBom($project, $this->owner);
        $this->approvedMaterialContribution($project, $this->lineOf($project, self::FERNITE_CARBIDE, 'reaction', 'final'), self::FERNITE_CARBIDE_UNITS_PER_RUN);

        $plannedProduction = $this->projectService->planOf($project, $this->owner);

        self::assertSame([0, 0], [\count($plannedProduction->plan->jobs), \count($plannedProduction->plan->leaves)]);
    }

    public function testFinalProductPartlyCoveredByItsStockPlansTheRunsLeftAndAddsItsStockToTheRequiredQuantity(): void
    {
        $project = $this->soloProjectOf([[self::FERNITE_CARBIDE, 2, self::PROJECT_MATERIAL_EFFICIENCY, self::PROJECT_TIME_EFFICIENCY]]);
        $this->projectService->buildBom($project, $this->owner);
        $finalLine = $this->lineOf($project, self::FERNITE_CARBIDE, 'reaction', 'final');
        $this->approvedMaterialContribution($project, $finalLine, self::FERNITE_CARBIDE_UNITS_PER_RUN);

        $this->projectService->buildBom($project, $this->owner);

        self::assertSame(
            [
                [self::HYDROGEN_FUEL_BLOCK, 'copy', 'blueprint', 1, 0, 0, 1],
                [self::HYDROGEN_FUEL_BLOCK, 'manufacturing', 'component', 1, 10, 20, 40],
                [self::FERNITE_ALLOY, 'reaction', 'component', 1, 0, 0, 200],
                [self::CERAMIC_POWDER, 'reaction', 'component', 1, 0, 0, 200],
                [self::FERNITE_CARBIDE, 'reaction', 'final', 1, 0, 0, 2 * self::FERNITE_CARBIDE_UNITS_PER_RUN],
            ],
            $this->jobs($project),
        );
        self::assertSame(self::FERNITE_CARBIDE_ONE_RUN_LEAVES, $this->materials($project));
        self::assertSame([self::FERNITE_CARBIDE_UNITS_PER_RUN, self::FERNITE_CARBIDE_UNITS_PER_RUN], [$finalLine->getRemainingQuantity(), $finalLine->getFulfilledQuantity()]);
    }

    public function testStockOfAnIntermediateSplitIntoTwoLinesIsAddedToTheLineOfTheMostRuns(): void
    {
        $project = $this->soloProjectOf([[self::FERNITE_CARBIDE, 1_000, self::PROJECT_MATERIAL_EFFICIENCY, self::PROJECT_TIME_EFFICIENCY]]);
        $this->projectService->buildBom($project, $this->owner);
        $hydrogenFuelBlockLines = array_values(array_filter(
            $this->jobLinesOf($project),
            static fn (IndustryProjectBomLine $line): bool => $line->getTypeId() === self::HYDROGEN_FUEL_BLOCK && $line->getActivityType() === 'manufacturing',
        ));
        self::assertCount(2, $hydrogenFuelBlockLines);
        $this->approvedMaterialContribution($project, $hydrogenFuelBlockLines[0], 40);

        $this->projectService->buildBom($project, $this->owner);

        $runsAndRequiredQuantities = array_map(
            static fn (IndustryProjectBomLine $line): array => [$line->getRuns(), $line->getRequiredQuantity()],
            array_values(array_filter(
                $this->jobLinesOf($project),
                static fn (IndustryProjectBomLine $line): bool => $line->getTypeId() === self::HYDROGEN_FUEL_BLOCK && $line->getActivityType() === 'manufacturing',
            )),
        );
        usort($runsAndRequiredQuantities, static fn (array $left, array $right): int => $right <=> $left);
        self::assertSame([[125, 5_040], [124, 4_960]], $runsAndRequiredQuantities);
    }

    public function testStockOfATypeThatIsNotInThePlanLeavesThePlanUnchanged(): void
    {
        $project = $this->soloProjectOf([[self::FERNITE_CARBIDE, 1, self::PROJECT_MATERIAL_EFFICIENCY, self::PROJECT_TIME_EFFICIENCY]]);
        $nitrogenFuelBlock = (new IndustryProjectBomLine())
            ->setProject($project)
            ->setTypeId(self::NITROGEN_FUEL_BLOCK)
            ->setTypeName('Nitrogen Fuel Block')
            ->setRequiredQuantity(40)
            ->setIsJob(true)
            ->setJobGroup('component')
            ->setActivityType('manufacturing')
            ->setRuns(1);
        $this->approvedMaterialContribution($project, $nitrogenFuelBlock, 25);

        $this->projectService->buildBom($project, $this->owner);

        self::assertSame(self::FERNITE_CARBIDE_ONE_RUN_LEAVES, $this->materials($project));
        self::assertSame(
            [
                [self::HYDROGEN_FUEL_BLOCK, 'copy', 'blueprint', 1, 0, 0, 1],
                [self::HYDROGEN_FUEL_BLOCK, 'manufacturing', 'component', 1, 10, 20, 40],
                [self::FERNITE_ALLOY, 'reaction', 'component', 1, 0, 0, 200],
                [self::CERAMIC_POWDER, 'reaction', 'component', 1, 0, 0, 200],
                [self::FERNITE_CARBIDE, 'reaction', 'final', 1, 0, 0, self::FERNITE_CARBIDE_UNITS_PER_RUN],
            ],
            $this->jobs($project),
        );
    }
}
