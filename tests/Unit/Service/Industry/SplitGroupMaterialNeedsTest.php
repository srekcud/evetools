<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectStep;
use App\Entity\User;
use App\Service\Industry\SplitGroupMaterialNeeds;
use App\Service\Industry\SplitGroupQuantities;
use App\Tests\Unit\State\Processor\Industry\BuildsSplitGroupMaterialNeeds;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(SplitGroupMaterialNeeds::class)]
#[CoversClass(SplitGroupQuantities::class)]
final class SplitGroupMaterialNeedsTest extends TestCase
{
    use BuildsSplitGroupMaterialNeeds;

    #[Group('issue-73')]
    public function testChangeOfJobsReturnsEveryMaterialStepWhoseNeedChanged(): void
    {
        $project = (new IndustryProject())->setUser(new User());
        $quantumMicroprocessor = $this->stepOf($project, self::QUANTUM_MICROPROCESSOR, 'manufacturing', runs: 32, quantity: 32);
        $this->stepOf($project, self::TITANIUM_CARBIDE, 'reaction', runs: 1, quantity: 490);
        $this->stepOf($project, self::PHENOLIC_COMPOSITES, 'reaction', runs: 1, quantity: 173);
        $this->stepOf($project, self::NANOTRANSISTORS, 'reaction', runs: 1, quantity: 58);

        $changedSteps = $this->splitGroupMaterialNeedsOnRecipes()->applyChangeOfJobs($project, $quantumMicroprocessor, [32], [11, 11, 10]);

        self::assertSame(
            [[self::TITANIUM_CARBIDE, 1, 491], [self::PHENOLIC_COMPOSITES, 1, 174]],
            array_map(
                static fn (IndustryProjectStep $changedStep): array => [$changedStep->getProductTypeId(), $changedStep->getRuns(), $changedStep->getQuantity()],
                $changedSteps,
            ),
        );
    }

    public function testChildSplitGroupOfAManualSplitKeepsOnlyTheJobsThatItsNewNeedRuns(): void
    {
        $project = (new IndustryProject())->setUser(new User());
        $quantumMicroprocessor = $this->stepOf($project, self::QUANTUM_MICROPROCESSOR, 'manufacturing', runs: 32, quantity: 32);
        $titaniumCarbideJobs = array_map(
            fn (int $splitIndex): IndustryProjectStep => $this->stepOf($project, self::TITANIUM_CARBIDE, 'reaction', runs: 1, quantity: $splitIndex === 0 ? 490 : 0)
                ->setSplitGroupId('titanium-carbide')
                ->setSplitIndex($splitIndex)
                ->setTotalGroupRuns(3),
            [0, 1, 2],
        );
        $this->stepOf($project, self::PHENOLIC_COMPOSITES, 'reaction', runs: 1, quantity: 173);

        $changedSteps = $this->splitGroupMaterialNeedsOnRecipes()->applyChangeOfJobs($project, $quantumMicroprocessor, [32], [11, 11, 10]);

        self::assertSame(
            [[1, 491, null, 0, null]],
            array_map(
                static fn (IndustryProjectStep $job): array => [$job->getRuns(), $job->getQuantity(), $job->getSplitGroupId(), $job->getSplitIndex(), $job->getTotalGroupRuns()],
                array_values(array_filter($project->getSteps()->toArray(), static fn (IndustryProjectStep $step): bool => $step->getProductTypeId() === self::TITANIUM_CARBIDE)),
            ),
        );
        self::assertSame([$titaniumCarbideJobs[0]], array_values(array_filter($changedSteps, static fn (IndustryProjectStep $step): bool => $step->getProductTypeId() === self::TITANIUM_CARBIDE)));
    }

    private function stepOf(IndustryProject $project, int $productTypeId, string $activityType, int $runs, int $quantity): IndustryProjectStep
    {
        $step = (new IndustryProjectStep())
            ->setProductTypeId($productTypeId)
            ->setBlueprintTypeId(self::blueprintOfRecipe($productTypeId))
            ->setActivityType($activityType)
            ->setRuns($runs)
            ->setQuantity($quantity)
            ->setDepth(1)
            ->setMeLevel($activityType === 'reaction' ? 0 : 10)
            ->setTeLevel($activityType === 'reaction' ? 0 : 20);
        $project->addStep($step);

        return $step;
    }
}
