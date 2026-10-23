<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry;

use App\Entity\IndustryProjectStep;
use App\Service\Industry\SplitGroupQuantities;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(SplitGroupQuantities::class)]
final class SplitGroupQuantitiesTest extends TestCase
{
    private const int OXYGEN_FUEL_BLOCK = 4312;
    private const int OXYGEN_FUEL_BLOCK_BLUEPRINT = 4313;
    private const int OXYGEN_FUEL_BLOCK_OUTPUT_PER_RUN = 40;
    private const int NITROGEN_FUEL_BLOCK = 4051;
    private const int NITROGEN_FUEL_BLOCK_BLUEPRINT = 4314;
    private const int NITROGEN_FUEL_BLOCK_OUTPUT_PER_RUN = 45;

    public function testSplitGroupWithoutRunsGivesOneRunToItsFirstJobAndTheRestOfTheNewRunsToItsLastJob(): void
    {
        $firstJob = $this->jobOfTheSplitGroup(splitIndex: 0);
        $lastJob = $this->jobOfTheSplitGroup(splitIndex: 1);

        $changedSteps = SplitGroupQuantities::applyNeedToSteps([$firstJob, $lastJob], 190, self::OXYGEN_FUEL_BLOCK_OUTPUT_PER_RUN);

        self::assertSame(
            [[1, 40, 0, 5], [4, 150, 1, 5]],
            array_map(
                static fn (IndustryProjectStep $job): array => [$job->getRuns(), $job->getQuantity(), $job->getSplitIndex(), $job->getTotalGroupRuns()],
                $changedSteps,
            ),
        );
    }

    #[Group('issue-185')]
    public function testAFixedJobKeepsItsRunsAndTheJobThatIsNotFixedTakesTheRestOfTheNeed(): void
    {
        $fixedJob = $this->jobOfTheSplitGroup(splitIndex: 0, runs: 15, runsFixed: true);
        $jobNotFixed = $this->jobOfTheSplitGroup(splitIndex: 1, runs: 8);

        SplitGroupQuantities::applyNeedToSteps([$fixedJob, $jobNotFixed], 790, self::OXYGEN_FUEL_BLOCK_OUTPUT_PER_RUN);

        self::assertSame([[15, 600, true, 20], [5, 190, false, 20]], $this->stateOf([$fixedJob, $jobNotFixed]));
    }

    #[Group('issue-185')]
    public function testJobsThatAreNotFixedShareTheRestOfTheNeedProrataOfTheirRuns(): void
    {
        $firstJob = $this->jobOfTheSplitGroup(splitIndex: 0, runs: 8);
        $fixedJob = $this->jobOfTheSplitGroup(splitIndex: 1, runs: 6, runsFixed: true);
        $lastJob = $this->jobOfTheSplitGroup(splitIndex: 2, runs: 4);

        SplitGroupQuantities::applyNeedToSteps([$firstJob, $fixedJob, $lastJob], 1200, self::OXYGEN_FUEL_BLOCK_OUTPUT_PER_RUN);

        self::assertSame([[16, 640, false, 30], [6, 240, true, 30], [8, 320, false, 30]], $this->stateOf([$firstJob, $fixedJob, $lastJob]));
    }

    #[Group('issue-185')]
    public function testAJobThatIsNotFixedKeepsOneRunWhenTheFixedJobCoversTheNeed(): void
    {
        $fixedJob = $this->jobOfTheSplitGroup(splitIndex: 0, runs: 15, runsFixed: true);
        $jobNotFixed = $this->jobOfTheSplitGroup(splitIndex: 1, runs: 5);

        SplitGroupQuantities::applyNeedToSteps([$fixedJob, $jobNotFixed], 480, self::OXYGEN_FUEL_BLOCK_OUTPUT_PER_RUN);

        self::assertSame(
            [[15, 600, true, 16], [1, false, 16]],
            [
                [$fixedJob->getRuns(), $fixedJob->getQuantity(), $fixedJob->isRunsFixed(), $fixedJob->getTotalGroupRuns()],
                [$jobNotFixed->getRuns(), $jobNotFixed->isRunsFixed(), $jobNotFixed->getTotalGroupRuns()],
            ],
        );
    }

    #[Group('issue-185')]
    public function testAGroupWhoseJobsAreAllFixedKeepsTheirRunsAboveTheNeed(): void
    {
        $firstJob = $this->jobOfTheSplitGroup(splitIndex: 0, runs: 25, runsFixed: true);
        $secondJob = $this->jobOfTheSplitGroup(splitIndex: 1, runs: 10, runsFixed: true);

        SplitGroupQuantities::applyNeedToSteps([$firstJob, $secondJob], 800, self::OXYGEN_FUEL_BLOCK_OUTPUT_PER_RUN);

        self::assertSame([[25, 1000, true, 35], [10, 400, true, 35]], $this->stateOf([$firstJob, $secondJob]));
    }

    #[Group('issue-185')]
    public function testAGroupWhoseJobsAreAllFixedKeepsTheirRunsBelowTheNeed(): void
    {
        $firstJob = $this->jobOfTheSplitGroup(splitIndex: 0, runs: 6, runsFixed: true);
        $secondJob = $this->jobOfTheSplitGroup(splitIndex: 1, runs: 4, runsFixed: true);

        SplitGroupQuantities::applyNeedToSteps([$firstJob, $secondJob], 1200, self::OXYGEN_FUEL_BLOCK_OUTPUT_PER_RUN);

        self::assertSame([[6, 240, true, 10], [4, 160, true, 10]], $this->stateOf([$firstJob, $secondJob]));
    }

    #[Group('issue-185')]
    public function testAFixedStepThatIsNotSplitKeepsItsRunsWhenTheNeedChanges(): void
    {
        $fixedStep = (new IndustryProjectStep())
            ->setProductTypeId(self::NITROGEN_FUEL_BLOCK)
            ->setBlueprintTypeId(self::NITROGEN_FUEL_BLOCK_BLUEPRINT)
            ->setActivityType('manufacturing')
            ->setRuns(15)
            ->setQuantity(15 * self::NITROGEN_FUEL_BLOCK_OUTPUT_PER_RUN)
            ->setRunsFixed(true);

        $changedSteps = SplitGroupQuantities::applyNeedToSteps([$fixedStep], 36 * self::NITROGEN_FUEL_BLOCK_OUTPUT_PER_RUN, self::NITROGEN_FUEL_BLOCK_OUTPUT_PER_RUN);

        self::assertSame([[], 15, 675, true], [$changedSteps, $fixedStep->getRuns(), $fixedStep->getQuantity(), $fixedStep->isRunsFixed()]);
    }

    /**
     * @param list<IndustryProjectStep> $jobs
     * @return list<array{int, int, bool, int|null}>
     */
    private function stateOf(array $jobs): array
    {
        return array_map(
            static fn (IndustryProjectStep $job): array => [$job->getRuns(), $job->getQuantity(), $job->isRunsFixed(), $job->getTotalGroupRuns()],
            $jobs,
        );
    }

    private function jobOfTheSplitGroup(int $splitIndex, int $runs = 0, bool $runsFixed = false): IndustryProjectStep
    {
        return (new IndustryProjectStep())
            ->setProductTypeId(self::OXYGEN_FUEL_BLOCK)
            ->setBlueprintTypeId(self::OXYGEN_FUEL_BLOCK_BLUEPRINT)
            ->setActivityType('manufacturing')
            ->setRuns($runs)
            ->setQuantity($runs * self::OXYGEN_FUEL_BLOCK_OUTPUT_PER_RUN)
            ->setRunsFixed($runsFixed)
            ->setSplitGroupId('oxygen-fuel-block')
            ->setSplitIndex($splitIndex)
            ->setTotalGroupRuns(0);
    }
}
