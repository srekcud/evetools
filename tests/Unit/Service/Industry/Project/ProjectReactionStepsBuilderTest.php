<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project;

use App\Entity\IndustryProjectBomLine;
use App\Industry\Domain\ActivityKind;
use App\Industry\Domain\OutputPerRun;
use App\Industry\Domain\Quantity;
use App\Industry\Domain\Recipe;
use App\Industry\Domain\RecipeMaterial;
use App\Industry\Domain\Runs;
use App\Service\Industry\Project\ProjectReactionStep;
use App\Service\Industry\Project\ProjectReactionStepsBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProjectReactionStepsBuilder::class)]
#[Group('issue-345')]
final class ProjectReactionStepsBuilderTest extends TestCase
{
    private const int FULLERIDES = 345_001;
    private const int HYPERSYNAPTIC_FIBERS = 345_002;
    private const int PARTICLE_ACCELERATOR_UNIT = 345_003;
    private const int HAMMERHEAD_II = 345_004;
    private const int TRITANIUM = 345_005;
    private const int CARBON_POLYMERS = 345_101;
    private const int PLATINUM_TECHNITE = 345_102;
    private const int NITROGEN_FUEL_BLOCK = 345_103;
    private const int HYDROGEN_FUEL_BLOCK = 345_104;
    private const int SILICON_DIOXIDE = 345_105;
    private const int FULLERIDES_HOMONYM = 345_050;

    private const int FULLERIDES_OUTPUT_PER_RUN = 3_000;
    private const int FULLERIDES_SECONDS_PER_RUN = 10_800;
    private const int FIBERS_OUTPUT_PER_RUN = 200;
    private const int FIBERS_SECONDS_PER_RUN = 21_600;

    private const array TYPE_NAMES = [
        self::FULLERIDES => 'Fullerides',
        self::HYPERSYNAPTIC_FIBERS => 'Hypersynaptic Fibers',
        self::PARTICLE_ACCELERATOR_UNIT => 'Particle Accelerator Unit',
        self::HAMMERHEAD_II => 'Hammerhead II',
        self::TRITANIUM => 'Tritanium',
        self::FULLERIDES_HOMONYM => 'Fullerides',
        self::CARBON_POLYMERS => 'Carbon Polymers',
        self::PLATINUM_TECHNITE => 'Platinum Technite',
        self::NITROGEN_FUEL_BLOCK => 'Nitrogen Fuel Block',
        self::HYDROGEN_FUEL_BLOCK => 'Hydrogen Fuel Block',
        self::SILICON_DIOXIDE => 'Silicon Dioxide',
    ];

    private const array FUEL_BLOCK_TYPE_IDS = [self::NITROGEN_FUEL_BLOCK, self::HYDROGEN_FUEL_BLOCK];

    private ProjectReactionStepsBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new ProjectReactionStepsBuilder();
    }

    public function testStepOfOneReactionRunReadsItsFormulaWithTheFuelBlockLastAndItsBaseDuration(): void
    {
        $steps = $this->build(
            [$this->reactionLine(self::FULLERIDES, 1, 3_000, 2_509, 'component')],
            [self::FULLERIDES => $this->fulleridesRecipe()],
        );

        self::assertCount(1, $steps);
        self::assertEquals(
            new ProjectReactionStep(
                self::FULLERIDES,
                'Fullerides',
                1,
                3_000,
                2_509,
                491,
                self::FULLERIDES_OUTPUT_PER_RUN,
                self::FULLERIDES_SECONDS_PER_RUN,
                10_800,
                [
                    ['typeId' => self::CARBON_POLYMERS, 'typeName' => 'Carbon Polymers', 'quantityPerRun' => 100, 'totalQuantity' => 100, 'isFuelBlock' => false],
                    ['typeId' => self::PLATINUM_TECHNITE, 'typeName' => 'Platinum Technite', 'quantityPerRun' => 100, 'totalQuantity' => 100, 'isFuelBlock' => false],
                    ['typeId' => self::NITROGEN_FUEL_BLOCK, 'typeName' => 'Nitrogen Fuel Block', 'quantityPerRun' => 5, 'totalQuantity' => 5, 'isFuelBlock' => true],
                ],
            ),
            $steps[0],
        );
    }

    public function testTwoJobLinesOfTheSameTypeMakeOneStepWithTheSumOfTheirRunsAndTheSurplusOfTheIntermediate(): void
    {
        $steps = $this->build(
            [
                $this->reactionLine(self::FULLERIDES, 3, 9_000, 4_000, 'component'),
                $this->reactionLine(self::FULLERIDES, 2, 6_000, 4_000, 'component'),
            ],
            [self::FULLERIDES => $this->fulleridesRecipe()],
        );

        self::assertCount(1, $steps);
        self::assertSame(
            [5, 15_000, 4_000, 11_000, 54_000, [500, 500, 25]],
            [
                $steps[0]->runs,
                $steps[0]->producedQuantity,
                $steps[0]->surplusQuantity,
                $steps[0]->neededQuantity,
                $steps[0]->baseTotalSeconds,
                array_column($steps[0]->inputs ?? [], 'totalQuantity'),
            ],
        );
    }

    public function testLineWithoutRecordedSurplusLeavesTheSurplusAndTheNeedUnknownButKeepsTheFormula(): void
    {
        $steps = $this->build(
            [
                $this->reactionLine(self::FULLERIDES, 3, 9_000, 4_000, 'component'),
                $this->reactionLine(self::FULLERIDES, 2, 6_000, null, 'component'),
            ],
            [self::FULLERIDES => $this->fulleridesRecipe()],
        );

        self::assertCount(1, $steps);
        self::assertNull($steps[0]->surplusQuantity);
        self::assertNull($steps[0]->neededQuantity);
        self::assertSame(15_000, $steps[0]->producedQuantity);
        self::assertSame(self::FULLERIDES_OUTPUT_PER_RUN, $steps[0]->outputPerRun);
        self::assertSame([100, 100, 5], array_column($steps[0]->inputs ?? [], 'quantityPerRun'));
    }

    public function testStepWithoutRecipeInTheSdeKeepsItsBomQuantitiesAndLeavesTheFormulaAndTheDurationUnknown(): void
    {
        $steps = $this->build(
            [$this->reactionLine(self::HYPERSYNAPTIC_FIBERS, 4, 800, 120, 'component')],
            [self::HYPERSYNAPTIC_FIBERS => null],
        );

        self::assertCount(1, $steps);
        self::assertEquals(
            new ProjectReactionStep(self::HYPERSYNAPTIC_FIBERS, 'Hypersynaptic Fibers', 4, 800, 120, 680, null, null, null, null),
            $steps[0],
        );
    }

    public function testStepWhoseRecipeIsNotAReactionIsTreatedAsWithoutFormula(): void
    {
        $manufacturingRecipe = new Recipe(
            self::HYPERSYNAPTIC_FIBERS,
            ActivityKind::Manufacturing,
            new OutputPerRun(self::FIBERS_OUTPUT_PER_RUN),
            [new RecipeMaterial(self::SILICON_DIOXIDE, new Quantity(30))],
            new Runs(100),
            self::FIBERS_SECONDS_PER_RUN,
        );

        $steps = $this->build(
            [$this->reactionLine(self::HYPERSYNAPTIC_FIBERS, 4, 800, 120, 'component')],
            [self::HYPERSYNAPTIC_FIBERS => $manufacturingRecipe],
        );

        self::assertCount(1, $steps);
        self::assertEquals(
            new ProjectReactionStep(self::HYPERSYNAPTIC_FIBERS, 'Hypersynaptic Fibers', 4, 800, 120, 680, null, null, null, null),
            $steps[0],
        );
    }

    public function testManufacturingJobsMaterialsAndCopiesMakeNoStep(): void
    {
        $steps = $this->build(
            [
                $this->jobLine(self::PARTICLE_ACCELERATOR_UNIT, 'component', 'manufacturing', 7, 7, 33),
                $this->jobLine(self::HAMMERHEAD_II, 'final', 'manufacturing', 20, 20, null),
                $this->jobLine(self::HAMMERHEAD_II, 'blueprint', 'copy', 20, 20, null),
                (new IndustryProjectBomLine())->setTypeId(self::TRITANIUM)->setTypeName('Tritanium')->setRequiredQuantity(12_000)->setIsJob(false),
            ],
            [],
        );

        self::assertSame([], $steps);
    }

    public function testProjectWithoutAnyBomLineHasNoStep(): void
    {
        self::assertSame([], $this->build([], []));
    }

    public function testStepsAreOrderedByTypeNameEvenWhenTheBomListsThemInAnotherOrder(): void
    {
        $steps = $this->build(
            [
                $this->reactionLine(self::HYPERSYNAPTIC_FIBERS, 2, 400, 0, 'component'),
                $this->reactionLine(self::FULLERIDES, 1, 3_000, 2_509, 'component'),
            ],
            [
                self::FULLERIDES => $this->fulleridesRecipe(),
                self::HYPERSYNAPTIC_FIBERS => $this->hypersynapticFibersRecipe(),
            ],
        );

        self::assertSame(['Fullerides', 'Hypersynaptic Fibers'], array_map(static fn (ProjectReactionStep $step): string => $step->typeName, $steps));
        self::assertSame([10_800, 43_200], array_map(static fn (ProjectReactionStep $step): ?int => $step->baseTotalSeconds, $steps));
        self::assertSame([3_000, 200], array_map(static fn (ProjectReactionStep $step): ?int => $step->outputPerRun, $steps));
    }

    public function testTwoStepsWithTheSameTypeNameAreOrderedByTypeId(): void
    {
        $steps = $this->build(
            [
                $this->reactionLine(self::FULLERIDES_HOMONYM, 1, 90, 10, 'component'),
                $this->reactionLine(self::FULLERIDES, 1, 3_000, 2_509, 'component'),
            ],
            [self::FULLERIDES => $this->fulleridesRecipe(), self::FULLERIDES_HOMONYM => null],
        );

        self::assertSame([self::FULLERIDES, self::FULLERIDES_HOMONYM], array_map(static fn (ProjectReactionStep $step): int => $step->typeId, $steps));
    }

    public function testInputsAreOrderedByTypeNameWithEveryFuelBlockAfterThem(): void
    {
        $recipe = new Recipe(
            self::HYPERSYNAPTIC_FIBERS,
            ActivityKind::Reaction,
            new OutputPerRun(self::FIBERS_OUTPUT_PER_RUN),
            [
                new RecipeMaterial(self::NITROGEN_FUEL_BLOCK, new Quantity(8)),
                new RecipeMaterial(self::SILICON_DIOXIDE, new Quantity(60)),
                new RecipeMaterial(self::HYDROGEN_FUEL_BLOCK, new Quantity(3)),
                new RecipeMaterial(self::CARBON_POLYMERS, new Quantity(40)),
            ],
            new Runs(100),
            self::FIBERS_SECONDS_PER_RUN,
        );

        $steps = $this->build(
            [$this->reactionLine(self::HYPERSYNAPTIC_FIBERS, 2, 400, 0, 'component')],
            [self::HYPERSYNAPTIC_FIBERS => $recipe],
        );

        self::assertCount(1, $steps);
        self::assertSame(
            [
                ['Carbon Polymers', 80, false],
                ['Silicon Dioxide', 120, false],
                ['Hydrogen Fuel Block', 6, true],
                ['Nitrogen Fuel Block', 16, true],
            ],
            array_map(
                static fn (array $input): array => [$input['typeName'], $input['totalQuantity'], $input['isFuelBlock']],
                $steps[0]->inputs ?? [],
            ),
        );
    }

    private function fulleridesRecipe(): Recipe
    {
        return new Recipe(
            self::FULLERIDES,
            ActivityKind::Reaction,
            new OutputPerRun(self::FULLERIDES_OUTPUT_PER_RUN),
            [
                new RecipeMaterial(self::NITROGEN_FUEL_BLOCK, new Quantity(5)),
                new RecipeMaterial(self::PLATINUM_TECHNITE, new Quantity(100)),
                new RecipeMaterial(self::CARBON_POLYMERS, new Quantity(100)),
            ],
            new Runs(100),
            self::FULLERIDES_SECONDS_PER_RUN,
        );
    }

    private function hypersynapticFibersRecipe(): Recipe
    {
        return new Recipe(
            self::HYPERSYNAPTIC_FIBERS,
            ActivityKind::Reaction,
            new OutputPerRun(self::FIBERS_OUTPUT_PER_RUN),
            [
                new RecipeMaterial(self::SILICON_DIOXIDE, new Quantity(60)),
                new RecipeMaterial(self::HYDROGEN_FUEL_BLOCK, new Quantity(3)),
            ],
            new Runs(100),
            self::FIBERS_SECONDS_PER_RUN,
        );
    }

    private function reactionLine(int $typeId, int $runs, int $quantity, ?int $surplus, string $jobGroup): IndustryProjectBomLine
    {
        return $this->jobLine($typeId, $jobGroup, 'reaction', $runs, $quantity, $surplus);
    }

    private function jobLine(int $typeId, string $jobGroup, string $activityType, int $runs, int $quantity, ?int $surplus): IndustryProjectBomLine
    {
        return (new IndustryProjectBomLine())
            ->setTypeId($typeId)
            ->setTypeName(self::TYPE_NAMES[$typeId])
            ->setRequiredQuantity($quantity)
            ->setIsJob(true)
            ->setJobGroup($jobGroup)
            ->setActivityType($activityType)
            ->setRuns($runs)
            ->setSurplusQuantity($surplus);
    }

    private function build(array $lines, array $recipes): array
    {
        return $this->builder->build($lines, $recipes, self::TYPE_NAMES, self::FUEL_BLOCK_TYPE_IDS);
    }
}
