<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry;

use App\Entity\IndustryProjectStep;
use App\Service\Industry\SplitGroupQuantities;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SplitGroupQuantities::class)]
final class SplitGroupQuantitiesTest extends TestCase
{
    private const int OXYGEN_FUEL_BLOCK = 4312;
    private const int OXYGEN_FUEL_BLOCK_BLUEPRINT = 4313;
    private const int OXYGEN_FUEL_BLOCK_OUTPUT_PER_RUN = 40;

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

    private function jobOfTheSplitGroup(int $splitIndex): IndustryProjectStep
    {
        return (new IndustryProjectStep())
            ->setProductTypeId(self::OXYGEN_FUEL_BLOCK)
            ->setBlueprintTypeId(self::OXYGEN_FUEL_BLOCK_BLUEPRINT)
            ->setActivityType('manufacturing')
            ->setRuns(0)
            ->setQuantity(0)
            ->setSplitGroupId('oxygen-fuel-block')
            ->setSplitIndex($splitIndex)
            ->setTotalGroupRuns(0);
    }
}
