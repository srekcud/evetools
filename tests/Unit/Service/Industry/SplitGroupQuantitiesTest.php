<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry;

use App\Entity\IndustryProjectStep;
use App\Entity\IndustryStepJobMatch;
use App\Entity\IndustryStepPurchase;
use App\Service\Industry\SplitGroupQuantities;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
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
    private const string SPLIT_GROUP_ID = 'oxygen-fuel-block';
    private const int HEAVY_WATER = 16272;

    public function testSplitGroupWithoutRunsGivesOneRunToItsFirstJobAndTheRestOfTheNewRunsToItsLastJob(): void
    {
        $firstJob = $this->jobOfTheSplitGroup(splitIndex: 0);
        $lastJob = $this->jobOfTheSplitGroup(splitIndex: 1);

        $changedSteps = SplitGroupQuantities::applyNeedToSteps([$firstJob, $lastJob], 190, self::OXYGEN_FUEL_BLOCK_OUTPUT_PER_RUN)->changedSteps;

        self::assertSame(
            [[1, 40, 0, 5], [4, 150, 1, 5]],
            array_map(
                static fn (IndustryProjectStep $job): array => [$job->getRuns(), $job->getQuantity(), $job->getSplitIndex(), $job->getTotalGroupRuns()],
                $changedSteps,
            ),
        );
    }

    #[Group('issue-252')]
    public function testJobWhoseRunsAndQuantityStayIsChangedWithTheNewTotalGroupRuns(): void
    {
        $firstJob = $this->jobOfTheSplitGroup(splitIndex: 0, runs: 3)->setTotalGroupRuns(5);
        $lastJob = $this->jobOfTheSplitGroup(splitIndex: 1, runs: 2)->setQuantity(70)->setTotalGroupRuns(5);

        $changedSteps = SplitGroupQuantities::applyNeedToSteps([$firstJob, $lastJob], 230, self::OXYGEN_FUEL_BLOCK_OUTPUT_PER_RUN)->changedSteps;

        self::assertSame([[4, 160, false, 6], [2, 70, false, 6]], $this->stateOf($changedSteps));
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
    public function testJobThatIsNotFixedIsRemovedAndTheFixedJobIsDissolvedWhenTheFixedJobCoversTheNeed(): void
    {
        $fixedJob = $this->jobOfTheSplitGroup(splitIndex: 0, runs: 15, runsFixed: true, totalGroupRuns: 20);
        $jobNotFixed = $this->jobOfTheSplitGroup(splitIndex: 1, runs: 5, totalGroupRuns: 20);

        $appliedNeed = SplitGroupQuantities::applyNeedToSteps([$fixedJob, $jobNotFixed], 480, self::OXYGEN_FUEL_BLOCK_OUTPUT_PER_RUN);

        self::assertSame([[15, 600, true, null, 0, null]], $this->splitStateOf([$fixedJob]));
        self::assertSame([$jobNotFixed], $appliedNeed->surplusJobs);
        self::assertSame([$fixedJob], $appliedNeed->changedSteps);
    }

    #[Group('issue-185')]
    public function testJobsThatAreNotFixedBeyondTheRestOfTheNeedAreRemovedFromTheLargestSplitIndex(): void
    {
        $fixedJob = $this->jobOfTheSplitGroup(splitIndex: 0, runs: 10, runsFixed: true, totalGroupRuns: 20);
        $secondJob = $this->jobOfTheSplitGroup(splitIndex: 1, runs: 5, totalGroupRuns: 20);
        $thirdJob = $this->jobOfTheSplitGroup(splitIndex: 2, runs: 5, totalGroupRuns: 20);

        $appliedNeed = SplitGroupQuantities::applyNeedToSteps([$fixedJob, $secondJob, $thirdJob], 440, self::OXYGEN_FUEL_BLOCK_OUTPUT_PER_RUN);

        self::assertSame(
            [[10, 400, true, self::SPLIT_GROUP_ID, 0, 11], [1, 40, false, self::SPLIT_GROUP_ID, 1, 11]],
            $this->splitStateOf([$fixedJob, $secondJob]),
        );
        self::assertSame([$thirdJob], $appliedNeed->surplusJobs);
        self::assertSame([$fixedJob, $secondJob], $appliedNeed->changedSteps);
    }

    #[Group('issue-185')]
    public function testKeptJobsAreRenumberedFromZeroInTheOrderOfTheirSplitIndex(): void
    {
        $firstFixedJob = $this->jobOfTheSplitGroup(splitIndex: 0, runs: 6, runsFixed: true, totalGroupRuns: 14);
        $jobNotFixed = $this->jobOfTheSplitGroup(splitIndex: 1, runs: 4, totalGroupRuns: 14);
        $lastFixedJob = $this->jobOfTheSplitGroup(splitIndex: 2, runs: 4, runsFixed: true, totalGroupRuns: 14);

        $appliedNeed = SplitGroupQuantities::applyNeedToSteps([$firstFixedJob, $jobNotFixed, $lastFixedJob], 400, self::OXYGEN_FUEL_BLOCK_OUTPUT_PER_RUN);

        self::assertSame(
            [[6, 240, true, self::SPLIT_GROUP_ID, 0, 10], [4, 160, true, self::SPLIT_GROUP_ID, 1, 10]],
            $this->splitStateOf([$firstFixedJob, $lastFixedJob]),
        );
        self::assertSame([$jobNotFixed], $appliedNeed->surplusJobs);
        self::assertSame([$firstFixedJob, $lastFixedJob], $appliedNeed->changedSteps);
    }

    #[DataProvider('commitmentsOfAJob')]
    public function testCommittedJobIsKeptBeforeAJobOfSmallerSplitIndex(\Closure $commit): void
    {
        $firstJob = $this->jobOfTheSplitGroup(splitIndex: 0, runs: 10, totalGroupRuns: 30);
        $secondJob = $this->jobOfTheSplitGroup(splitIndex: 1, runs: 10, totalGroupRuns: 30);
        $committedJob = $commit($this->jobOfTheSplitGroup(splitIndex: 2, runs: 10, totalGroupRuns: 30));

        $appliedNeed = SplitGroupQuantities::applyNeedToSteps([$firstJob, $secondJob, $committedJob], 80, self::OXYGEN_FUEL_BLOCK_OUTPUT_PER_RUN);

        self::assertSame(
            [[1, 40, false, self::SPLIT_GROUP_ID, 0, 2], [1, 40, false, self::SPLIT_GROUP_ID, 1, 2]],
            $this->splitStateOf([$firstJob, $committedJob]),
        );
        self::assertSame([$secondJob], $appliedNeed->surplusJobs);
    }

    public static function commitmentsOfAJob(): iterable
    {
        yield 'matched ESI job' => [static fn (IndustryProjectStep $job): IndustryProjectStep => $job->addJobMatch((new IndustryStepJobMatch())->setEsiJobId(7_130_412)->setRuns(10))];
        yield 'purchase' => [static fn (IndustryProjectStep $job): IndustryProjectStep => $job->addPurchase((new IndustryStepPurchase())->setTypeId(self::OXYGEN_FUEL_BLOCK)->setQuantity(120)->setUnitPrice(18.5)->setTotalPrice(2_220.0)->setSource('manual'))];
        yield 'stock' => [static fn (IndustryProjectStep $job): IndustryProjectStep => $job->setInStockQuantity(30)];
        yield 'purchased mark' => [static fn (IndustryProjectStep $job): IndustryProjectStep => $job->setPurchased(true)];
    }

    public function testCommittedSurplusJobPassesItsMatchedJobAndStockToTheKeptJobOfTheDissolvedGroup(): void
    {
        $firstJob = $this->jobOfTheSplitGroup(splitIndex: 0, runs: 10, totalGroupRuns: 30);
        $matchedJobOfTheSecondJob = (new IndustryStepJobMatch())->setEsiJobId(7_130_501)->setRuns(10);
        $secondJob = $this->jobOfTheSplitGroup(splitIndex: 1, runs: 10, totalGroupRuns: 30)->addJobMatch($matchedJobOfTheSecondJob);
        $matchedJobOfTheThirdJob = (new IndustryStepJobMatch())->setEsiJobId(7_130_502)->setRuns(10);
        $thirdJob = $this->jobOfTheSplitGroup(splitIndex: 2, runs: 10, totalGroupRuns: 30)->addJobMatch($matchedJobOfTheThirdJob)->setInStockQuantity(15);

        $appliedNeed = SplitGroupQuantities::applyNeedToSteps([$firstJob, $secondJob, $thirdJob], 40, self::OXYGEN_FUEL_BLOCK_OUTPUT_PER_RUN);

        self::assertSame([[1, 40, false, null, 0, null]], $this->splitStateOf([$secondJob]));
        self::assertSame([[7_130_501, 7_130_502], 15], [$this->esiJobIdsOf($secondJob), $secondJob->getInStockQuantity()]);
        self::assertSame([$matchedJobOfTheSecondJob, $matchedJobOfTheThirdJob], array_values($secondJob->getJobMatches()->toArray()));
        self::assertSame([$firstJob, $thirdJob], $appliedNeed->surplusJobs);
        self::assertSame([$secondJob], $appliedNeed->changedSteps);
    }

    public function testSurplusJobsAreAbsorbedByTheKeptJobOfLargestSplitIndex(): void
    {
        $matchedJobs = array_map(static fn (int $esiJobId): IndustryStepJobMatch => (new IndustryStepJobMatch())->setEsiJobId($esiJobId)->setRuns(5), [7_130_601, 7_130_602, 7_130_603, 7_130_604]);
        $purchase = (new IndustryStepPurchase())->setTypeId(self::HEAVY_WATER)->setQuantity(1_350)->setUnitPrice(41.2)->setTotalPrice(55_620.0)->setSource('manual');
        $jobs = array_map(
            fn (int $splitIndex): IndustryProjectStep => $this->jobOfTheSplitGroup(splitIndex: $splitIndex, runs: 5, totalGroupRuns: 20)->addJobMatch($matchedJobs[$splitIndex]),
            [0, 1, 2, 3],
        );
        $jobs[3]->setPurchased(true)->addPurchase($purchase);

        $appliedNeed = SplitGroupQuantities::applyNeedToSteps($jobs, 80, self::OXYGEN_FUEL_BLOCK_OUTPUT_PER_RUN);

        self::assertSame(
            [[[7_130_601], 0, false], [[7_130_602, 7_130_603, 7_130_604], 1, true]],
            [
                [$this->esiJobIdsOf($jobs[0]), $jobs[0]->getPurchases()->count(), $jobs[0]->isPurchased()],
                [$this->esiJobIdsOf($jobs[1]), $jobs[1]->getPurchases()->count(), $jobs[1]->isPurchased()],
            ],
        );
        self::assertSame([$purchase], array_values($jobs[1]->getPurchases()->toArray()));
        self::assertSame([$jobs[2], $jobs[3]], $appliedNeed->surplusJobs);
    }

    public function testRestOfTheNeedIsSharedSoThatTheLastJobKeepsOneRun(): void
    {
        $firstJob = $this->jobOfTheSplitGroup(splitIndex: 0, runs: 8, totalGroupRuns: 10);
        $secondJob = $this->jobOfTheSplitGroup(splitIndex: 1, runs: 1, totalGroupRuns: 10);
        $lastJob = $this->jobOfTheSplitGroup(splitIndex: 2, runs: 1, totalGroupRuns: 10);

        $appliedNeed = SplitGroupQuantities::applyNeedToSteps([$firstJob, $secondJob, $lastJob], 120, self::OXYGEN_FUEL_BLOCK_OUTPUT_PER_RUN);

        self::assertSame(
            [[1, 40, false, self::SPLIT_GROUP_ID, 0, 3], [1, 40, false, self::SPLIT_GROUP_ID, 1, 3], [1, 40, false, self::SPLIT_GROUP_ID, 2, 3]],
            $this->splitStateOf([$firstJob, $secondJob, $lastJob]),
        );
        self::assertSame([], $appliedNeed->surplusJobs);
    }

    public function testGroupWithoutNeedKeepsItsFirstJobDissolvedWithoutRuns(): void
    {
        $firstJob = $this->jobOfTheSplitGroup(splitIndex: 0, runs: 1, totalGroupRuns: 2);
        $lastJob = $this->jobOfTheSplitGroup(splitIndex: 1, runs: 1, totalGroupRuns: 2);

        $appliedNeed = SplitGroupQuantities::applyNeedToSteps([$firstJob, $lastJob], 0, self::OXYGEN_FUEL_BLOCK_OUTPUT_PER_RUN);

        self::assertSame([[0, 0, false, null, 0, null]], $this->splitStateOf([$firstJob]));
        self::assertSame([$lastJob], $appliedNeed->surplusJobs);
    }

    #[Group('issue-185')]
    public function testAGroupWhoseJobsAreAllFixedKeepsTheirRunsAboveTheNeed(): void
    {
        $firstJob = $this->jobOfTheSplitGroup(splitIndex: 0, runs: 25, runsFixed: true);
        $secondJob = $this->jobOfTheSplitGroup(splitIndex: 1, runs: 10, runsFixed: true);

        $appliedNeed = SplitGroupQuantities::applyNeedToSteps([$firstJob, $secondJob], 800, self::OXYGEN_FUEL_BLOCK_OUTPUT_PER_RUN);

        self::assertSame([[25, 1000, true, 35], [10, 400, true, 35]], $this->stateOf([$firstJob, $secondJob]));
        self::assertSame([], $appliedNeed->surplusJobs);
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

        $changedSteps = SplitGroupQuantities::applyNeedToSteps([$fixedStep], 36 * self::NITROGEN_FUEL_BLOCK_OUTPUT_PER_RUN, self::NITROGEN_FUEL_BLOCK_OUTPUT_PER_RUN)->changedSteps;

        self::assertSame([[], 15, 675, true], [$changedSteps, $fixedStep->getRuns(), $fixedStep->getQuantity(), $fixedStep->isRunsFixed()]);
    }

    #[Group('issue-171')]
    #[DataProvider('needsAndTheirRuns')]
    public function testRunsOfANeedAreTheWholeRunsThatProduceIt(int $need, int $outputPerRun, int $expectedRuns): void
    {
        self::assertSame($expectedRuns, SplitGroupQuantities::runsOfNeed($need, $outputPerRun));
    }

    public static function needsAndTheirRuns(): iterable
    {
        yield 'need of exactly 152 runs of 200 units' => [30400, 200, 152];
        yield 'need of 142.82 runs of 200 units rounds up' => [28564, 200, 143];
        yield 'need of 11.095 runs of 200 units rounds up' => [2219, 200, 12];
        yield 'need of exactly 57 runs of 3 units' => [171, 3, 57];
        yield 'need of one unit more than 40 runs of 40 units' => [1601, 40, 41];
        yield 'need of one unit' => [1, 45, 1];
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

    private function splitStateOf(array $jobs): array
    {
        return array_map(
            static fn (IndustryProjectStep $job): array => [$job->getRuns(), $job->getQuantity(), $job->isRunsFixed(), $job->getSplitGroupId(), $job->getSplitIndex(), $job->getTotalGroupRuns()],
            $jobs,
        );
    }

    private function esiJobIdsOf(IndustryProjectStep $job): array
    {
        return array_values(array_map(static fn (IndustryStepJobMatch $matchedJob): int => $matchedJob->getEsiJobId(), $job->getJobMatches()->toArray()));
    }

    private function jobOfTheSplitGroup(int $splitIndex, int $runs = 0, bool $runsFixed = false, int $totalGroupRuns = 0): IndustryProjectStep
    {
        return (new IndustryProjectStep())
            ->setProductTypeId(self::OXYGEN_FUEL_BLOCK)
            ->setBlueprintTypeId(self::OXYGEN_FUEL_BLOCK_BLUEPRINT)
            ->setActivityType('manufacturing')
            ->setRuns($runs)
            ->setQuantity($runs * self::OXYGEN_FUEL_BLOCK_OUTPUT_PER_RUN)
            ->setRunsFixed($runsFixed)
            ->setSplitGroupId(self::SPLIT_GROUP_ID)
            ->setSplitIndex($splitIndex)
            ->setTotalGroupRuns($totalGroupRuns);
    }
}
