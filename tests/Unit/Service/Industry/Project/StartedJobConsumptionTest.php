<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project;

use App\Entity\CachedIndustryJob;
use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectJobMatch;
use App\Service\Industry\Project\StartedJobConsumption;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(StartedJobConsumption::class)]
#[Group('issue-1140')]
final class StartedJobConsumptionTest extends TestCase
{
    use BuildsChainBomLines;

    private const int CADMIUM = 16_647;
    private const int SILICON = 16_636;
    private const int HYDROGEN_FUEL_BLOCK = 4_246;
    private const int ZYDRINE = 39;
    private const int PHOTONIC_UNIT = 16_680;
    private const int CARBON_POLYMERS = 16_659;
    private const int REACTION_PRODUCT = 1_140_001;
    private const int PARENT_PRODUCT = 1_140_002;
    private const int FINAL_PRODUCT = 1_140_003;

    private int $nextEsiJobId = 1_140_000;

    public function testNoneConsumesNothingOfAnyType(): void
    {
        $consumption = StartedJobConsumption::none();

        self::assertFalse($consumption->isUnknown());
        self::assertSame(0, $consumption->consumedOf(self::CADMIUM));
    }

    public function testUnknownRefusesToTellAConsumedQuantity(): void
    {
        $consumption = StartedJobConsumption::unknown();

        self::assertTrue($consumption->isUnknown());
        $this->expectException(\LogicException::class);

        $consumption->consumedOf(self::CADMIUM);
    }

    public function testAnActiveReactionOverAllItsRunsConsumesTheWholeNeedOfEachMaterial(): void
    {
        $line = $this->startedLine(33, [[CachedIndustryJob::STATUS_ACTIVE, 33]]);

        $consumption = StartedJobConsumption::of([$line], [self::lineId($line) => [self::CADMIUM => 3_213, self::SILICON => 1_650]]);

        self::assertFalse($consumption->isUnknown());
        self::assertSame([3_213, 1_650, 0], [
            $consumption->consumedOf(self::CADMIUM),
            $consumption->consumedOf(self::SILICON),
            $consumption->consumedOf(self::HYDROGEN_FUEL_BLOCK),
        ]);
    }

    public function testQuantitiesByTypeIdListsEveryConsumedTypeAndNothingElse(): void
    {
        $reactionLine = $this->startedLine(33, [[CachedIndustryJob::STATUS_ACTIVE, 33]], self::REACTION_PRODUCT);
        $parentLine = $this->startedLine(10, [[CachedIndustryJob::STATUS_DELIVERED, 5]], self::PARENT_PRODUCT);

        $consumption = StartedJobConsumption::of([$reactionLine, $parentLine], [
            self::lineId($reactionLine) => [self::CADMIUM => 3_213, self::SILICON => 1_650],
            self::lineId($parentLine) => [self::CADMIUM => 300, self::ZYDRINE => 45],
        ]);

        self::assertSame([self::CADMIUM => 3_363, self::SILICON => 1_650, self::ZYDRINE => 22], $consumption->quantitiesByTypeId());
    }

    public function testQuantitiesByTypeIdRefusesToListTheQuantitiesOfAnUnknownConsumption(): void
    {
        $this->expectException(\LogicException::class);

        StartedJobConsumption::unknown()->quantitiesByTypeId();
    }

    public function testADeliveredJobCoveringEveryRunConsumesTheWholeNeed(): void
    {
        $line = $this->startedLine(10, [[CachedIndustryJob::STATUS_DELIVERED, 10]]);

        $consumption = StartedJobConsumption::of([$line], [self::lineId($line) => [self::CADMIUM => 1_000]]);

        self::assertSame(1_000, $consumption->consumedOf(self::CADMIUM));
    }

    public function testAJobOverFewerRunsThanTheLineConsumesTheShareOfItsRuns(): void
    {
        $line = $this->startedLine(10, [[CachedIndustryJob::STATUS_ACTIVE, 4]]);

        $consumption = StartedJobConsumption::of([$line], [self::lineId($line) => [self::CADMIUM => 1_000, self::ZYDRINE => 250]]);

        self::assertSame([400, 100], [$consumption->consumedOf(self::CADMIUM), $consumption->consumedOf(self::ZYDRINE)]);
    }

    public function testTheRunsOfTwoStartedJobsOfTheSameLineAddUp(): void
    {
        $line = $this->startedLine(10, [[CachedIndustryJob::STATUS_ACTIVE, 4], [CachedIndustryJob::STATUS_READY, 3]]);

        $consumption = StartedJobConsumption::of([$line], [self::lineId($line) => [self::CADMIUM => 1_000]]);

        self::assertSame(700, $consumption->consumedOf(self::CADMIUM));
    }

    public function testAPausedJobCountsAsStartedBecauseItsMaterialsAreAlreadyTaken(): void
    {
        $line = $this->startedLine(10, [[CachedIndustryJob::STATUS_PAUSED, 5]]);

        $consumption = StartedJobConsumption::of([$line], [self::lineId($line) => [self::CADMIUM => 1_000]]);

        self::assertSame(500, $consumption->consumedOf(self::CADMIUM));
    }

    public function testACancelledJobConsumesNothing(): void
    {
        $line = $this->startedLine(10, [[CachedIndustryJob::STATUS_CANCELLED, 10]]);

        $consumption = StartedJobConsumption::of([$line], [self::lineId($line) => [self::CADMIUM => 1_000]]);

        self::assertSame(0, $consumption->consumedOf(self::CADMIUM));
    }

    public function testARevertedJobConsumesNothing(): void
    {
        $line = $this->startedLine(10, [[CachedIndustryJob::STATUS_REVERTED, 10]]);

        $consumption = StartedJobConsumption::of([$line], [self::lineId($line) => [self::CADMIUM => 1_000]]);

        self::assertSame(0, $consumption->consumedOf(self::CADMIUM));
    }

    public function testACancelledJobOnALineMissingFromTheNeedsDoesNotMakeTheConsumptionUnknown(): void
    {
        $line = $this->startedLine(10, [[CachedIndustryJob::STATUS_CANCELLED, 10]]);

        $consumption = StartedJobConsumption::of([$line], []);

        self::assertFalse($consumption->isUnknown());
        self::assertSame(0, $consumption->consumedOf(self::CADMIUM));
    }

    public function testTwoJobLinesNeedingTheSameTypeAddUpTheirConsumptionPastWhatOneLineNeeds(): void
    {
        $firstLine = $this->startedLine(10, [[CachedIndustryJob::STATUS_ACTIVE, 10]], self::REACTION_PRODUCT);
        $secondLine = $this->startedLine(3, [[CachedIndustryJob::STATUS_ACTIVE, 3]], self::PARENT_PRODUCT);

        $consumption = StartedJobConsumption::of([$firstLine, $secondLine], [
            self::lineId($firstLine) => [self::CADMIUM => 1_000],
            self::lineId($secondLine) => [self::CADMIUM => 300, self::PHOTONIC_UNIT => 45],
        ]);

        self::assertSame([1_300, 45], [$consumption->consumedOf(self::CADMIUM), $consumption->consumedOf(self::PHOTONIC_UNIT)]);
    }

    public function testTheConsumptionOfAShareOfRunsIsRoundedDownToAWholeUnit(): void
    {
        $line = $this->startedLine(3, [[CachedIndustryJob::STATUS_ACTIVE, 1]]);

        $consumption = StartedJobConsumption::of([$line], [self::lineId($line) => [self::CADMIUM => 100]]);

        self::assertSame(33, $consumption->consumedOf(self::CADMIUM));
    }

    public function testJobRunsBeyondTheRunsOfTheLineAreCappedToTheRunsOfTheLine(): void
    {
        $line = $this->startedLine(10, [[CachedIndustryJob::STATUS_ACTIVE, 6], [CachedIndustryJob::STATUS_DELIVERED, 8]]);

        $consumption = StartedJobConsumption::of([$line], [self::lineId($line) => [self::CADMIUM => 1_000]]);

        self::assertSame(1_000, $consumption->consumedOf(self::CADMIUM));
    }

    public function testAJobOfAComponentLineConsumesTheIntermediateItsNeedsListAsAMaterial(): void
    {
        $parentLine = $this->startedLine(1, [[CachedIndustryJob::STATUS_ACTIVE, 1]], self::PARENT_PRODUCT);

        $consumption = StartedJobConsumption::of([$parentLine], [self::lineId($parentLine) => [self::REACTION_PRODUCT => 30]]);

        self::assertSame(30, $consumption->consumedOf(self::REACTION_PRODUCT));
    }

    public function testTheConsumptionIsUnknownWhenThePlanIsUnavailableAndAJobIsStarted(): void
    {
        $line = $this->startedLine(10, [[CachedIndustryJob::STATUS_ACTIVE, 10]]);

        $consumption = StartedJobConsumption::of([$line], null);

        self::assertTrue($consumption->isUnknown());
    }

    public function testTheConsumptionIsUnknownWhenAStartedLineHasNoEntryInTheNeeds(): void
    {
        $matchedLine = $this->startedLine(10, [[CachedIndustryJob::STATUS_DELIVERED, 10]], self::REACTION_PRODUCT);
        $unmatchedLine = $this->startedLine(4, [[CachedIndustryJob::STATUS_ACTIVE, 4]], self::PARENT_PRODUCT);

        $consumption = StartedJobConsumption::of([$matchedLine, $unmatchedLine], [self::lineId($matchedLine) => [self::CADMIUM => 1_000]]);

        self::assertTrue($consumption->isUnknown());
    }

    public function testTheConsumptionIsNotUnknownWithoutAnyStartedJobEvenWhenThePlanIsUnavailable(): void
    {
        $lineWithoutJob = $this->startedLine(10, []);

        $consumption = StartedJobConsumption::of([$lineWithoutJob], null);

        self::assertFalse($consumption->isUnknown());
        self::assertSame(0, $consumption->consumedOf(self::CADMIUM));
    }

    public function testALineWithoutRunsIgnoresItsJobsAndNeverMakesTheConsumptionUnknown(): void
    {
        $coveredLine = $this->startedLine(0, [[CachedIndustryJob::STATUS_ACTIVE, 3]], self::REACTION_PRODUCT);
        $startedLine = $this->startedLine(10, [[CachedIndustryJob::STATUS_ACTIVE, 5]], self::FINAL_PRODUCT);

        $consumption = StartedJobConsumption::of([$coveredLine, $startedLine], [self::lineId($startedLine) => [self::CARBON_POLYMERS => 840]]);

        self::assertFalse($consumption->isUnknown());
        self::assertSame([420, 0], [$consumption->consumedOf(self::CARBON_POLYMERS), $consumption->consumedOf(self::CADMIUM)]);
    }

    public function testAJobLineWithoutRunsCarryingAStartedJobIsRejected(): void
    {
        $line = $this->startedLine(10, [[CachedIndustryJob::STATUS_ACTIVE, 10]]);
        $line->setRuns(null);

        $this->expectException(\UnexpectedValueException::class);

        StartedJobConsumption::of([$line], [self::lineId($line) => [self::CADMIUM => 1_000]]);
    }

    private function startedLine(int $lineRuns, array $matches, int $productTypeId = self::REACTION_PRODUCT): IndustryProjectBomLine
    {
        $line = $this->jobLine($productTypeId, 'Product '.$productTypeId, 'component', null, $lineRuns);
        foreach ($matches as [$status, $runs]) {
            $line->addJobMatch(
                (new IndustryProjectJobMatch())
                    ->setEsiJobId(++$this->nextEsiJobId)
                    ->setStatus($status)
                    ->setRuns($runs),
            );
        }

        return $line;
    }
}
