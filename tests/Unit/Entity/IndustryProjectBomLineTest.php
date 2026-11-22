<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectBomLineMark;
use App\Entity\IndustryProjectJobMatch;
use App\Enum\BomLineMarkState;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(IndustryProjectBomLine::class)]
#[Group('issue-107')]
final class IndustryProjectBomLineTest extends TestCase
{
    public function testJobLineOfOneRunIsFulfilledByTheUnitsProducedByThatRun(): void
    {
        $finalJob = (new IndustryProjectBomLine())
            ->setIsJob(true)
            ->setActivityType('manufacturing')
            ->setRuns(1)
            ->setRequiredQuantity(40)
            ->setFulfilledQuantity(40);

        self::assertSame([true, 0, 100.0], [$finalJob->isFulfilled(), $finalJob->getRemainingQuantity(), $finalJob->getFulfillmentPercent()]);
    }

    public function testJobLineOfOneRunIsNotFulfilledByASingleUnit(): void
    {
        $finalJob = (new IndustryProjectBomLine())
            ->setIsJob(true)
            ->setActivityType('manufacturing')
            ->setRuns(1)
            ->setRequiredQuantity(40)
            ->setFulfilledQuantity(1);

        self::assertSame([false, 39, 2.5], [$finalJob->isFulfilled(), $finalJob->getRemainingQuantity(), $finalJob->getFulfillmentPercent()]);
    }

    #[Group('issue-337')]
    public function testLineHasNoPlannedStructureUntilThePlanSetsOne(): void
    {
        $line = new IndustryProjectBomLine();

        self::assertSame([null, null], [$line->getPlannedStructureId(), $line->getPlannedStructureStatus()]);
    }

    #[Group('issue-337')]
    public function testLineKeepsThePlannedStructureAndHowItWasChosen(): void
    {
        $finalJob = (new IndustryProjectBomLine())
            ->setPlannedStructureId('01970000-0000-7000-8000-0000000000a1')
            ->setPlannedStructureStatus('best_in_favorite_system');

        self::assertSame(
            ['01970000-0000-7000-8000-0000000000a1', 'best_in_favorite_system'],
            [$finalJob->getPlannedStructureId(), $finalJob->getPlannedStructureStatus()],
        );
    }

    #[Group('issue-706')]
    public function testJobLineOfZeroRunsIsCoveredByStock(): void
    {
        $coveredJob = (new IndustryProjectBomLine())
            ->setIsJob(true)
            ->setActivityType('manufacturing')
            ->setRuns(0)
            ->setRequiredQuantity(15)
            ->setFulfilledQuantity(20);

        self::assertTrue($coveredJob->isCoveredByStock());
    }

    #[Group('issue-706')]
    public function testJobLineOfOneRunIsNotCoveredByStockWhateverItsFulfilledQuantity(): void
    {
        $partlyCoveredJob = (new IndustryProjectBomLine())
            ->setIsJob(true)
            ->setActivityType('reaction')
            ->setRuns(1)
            ->setRequiredQuantity(10_030)
            ->setFulfilledQuantity(10_030);

        self::assertFalse($partlyCoveredJob->isCoveredByStock());
    }

    #[Group('issue-706')]
    public function testMaterialLineIsNeverCoveredByStock(): void
    {
        $leaf = (new IndustryProjectBomLine())
            ->setIsJob(false)
            ->setRequiredQuantity(405)
            ->setFulfilledQuantity(405);

        self::assertFalse($leaf->isCoveredByStock());
    }

    #[Group('issue-807')]
    public function testCountedRunsAreTheRunsOfTheStepMarkWhenNoJobIsLinked(): void
    {
        $inventionLine = $this->jobLineOfRuns(10)->setMark($this->stepMark(BomLineMarkState::Done, 10));

        self::assertSame([0, 10], [$inventionLine->getMatchedRuns(), $inventionLine->getCountedRuns()]);
    }

    #[Group('issue-807')]
    public function testCountedRunsNeverAddTheStepMarkToTheMatchedRuns(): void
    {
        $componentLine = $this->jobLineOfRuns(10)->setMark($this->stepMark(BomLineMarkState::Done, 10));
        $componentLine->addJobMatch((new IndustryProjectJobMatch())->setEsiJobId(807_001)->setRuns(6)->setStatus('delivered'));

        self::assertSame([6, 10], [$componentLine->getMatchedRuns(), $componentLine->getCountedRuns()]);
    }

    #[Group('issue-807')]
    public function testCountedRunsAreTheMatchedRunsWhenTheyExceedTheRunsOfTheStepMark(): void
    {
        $componentLine = $this->jobLineOfRuns(10)->setMark($this->stepMark(BomLineMarkState::Launched, 10));
        $componentLine->addJobMatch((new IndustryProjectJobMatch())->setEsiJobId(807_002)->setRuns(14)->setStatus('active'));

        self::assertSame(14, $componentLine->getCountedRuns());
    }

    #[Group('issue-807')]
    public function testCountedRunsCapTheStepMarkAtTheRunsOfTheLine(): void
    {
        $componentLine = $this->jobLineOfRuns(10)->setMark($this->stepMark(BomLineMarkState::Done, 12));

        self::assertSame(10, $componentLine->getCountedRuns());
    }

    #[Group('issue-807')]
    public function testCountedRunsAreTheMatchedRunsWhenTheLineHasNoStepMark(): void
    {
        $componentLine = $this->jobLineOfRuns(10);
        $componentLine->addJobMatch((new IndustryProjectJobMatch())->setEsiJobId(807_003)->setRuns(6)->setStatus('delivered'));

        self::assertSame([null, 6], [$componentLine->getMark(), $componentLine->getCountedRuns()]);
    }

    #[Group('issue-997')]
    public function testDeliveredRunsAreTheRunsOfTheDoneMarkWhenNoJobIsLinked(): void
    {
        $line = $this->jobLineOfRuns(2)->setMark($this->stepMark(BomLineMarkState::Done, 2));

        self::assertSame(2, $line->getDeliveredRuns());
    }

    #[Group('issue-997')]
    public function testDeliveredRunsNeverAddTheDoneMarkToTheDeliveredJobRuns(): void
    {
        $line = $this->jobLineOfRuns(10)->setMark($this->stepMark(BomLineMarkState::Done, 10));
        $line->addJobMatch((new IndustryProjectJobMatch())->setEsiJobId(997_001)->setRuns(6)->setStatus('delivered'));

        self::assertSame(10, $line->getDeliveredRuns());
    }

    #[Group('issue-997')]
    public function testDeliveredRunsAreTheDeliveredJobRunsWhenTheyExceedTheDoneMark(): void
    {
        $line = $this->jobLineOfRuns(10)->setMark($this->stepMark(BomLineMarkState::Done, 4));
        $line->addJobMatch((new IndustryProjectJobMatch())->setEsiJobId(997_002)->setRuns(6)->setStatus('delivered'));

        self::assertSame(6, $line->getDeliveredRuns());
    }

    #[Group('issue-997')]
    public function testDeliveredRunsCapTheDoneMarkAtTheRunsOfTheLine(): void
    {
        $line = $this->jobLineOfRuns(10)->setMark($this->stepMark(BomLineMarkState::Done, 12));

        self::assertSame(10, $line->getDeliveredRuns());
    }

    #[Group('issue-997')]
    public function testDeliveredRunsIgnoreALaunchedMark(): void
    {
        $line = $this->jobLineOfRuns(10)->setMark($this->stepMark(BomLineMarkState::Launched, 10));

        self::assertSame(0, $line->getDeliveredRuns());
    }

    #[Group('issue-997')]
    public function testDeliveredRunsFallBackToTheDeliveredJobRunsOnceTheMarkIsRemoved(): void
    {
        $line = $this->jobLineOfRuns(10)->setMark($this->stepMark(BomLineMarkState::Done, 10));
        $line->addJobMatch((new IndustryProjectJobMatch())->setEsiJobId(997_003)->setRuns(6)->setStatus('delivered'));
        $line->addJobMatch((new IndustryProjectJobMatch())->setEsiJobId(997_004)->setRuns(3)->setStatus('active'));
        $line->setMark(null);

        self::assertSame(6, $line->getDeliveredRuns());
    }

    #[Group('issue-1202')]
    public function testQuantityOfRunsOfAManufacturingLineWithAnOutputOfOneIsTheRuns(): void
    {
        $line = $this->jobLineOfRuns(10);

        self::assertSame(4, $line->getQuantityOfRuns(4));
    }

    #[Group('issue-1202')]
    public function testQuantityOfRunsOfALineWithOneRunIsItsRequiredQuantity(): void
    {
        $line = $this->jobLineOfRuns(1)->setRequiredQuantity(7);

        self::assertSame(7, $line->getQuantityOfRuns(1));
    }

    #[Group('issue-1202')]
    public function testQuantityOfRunsOfAManufacturingLineMultipliesTheRunsByItsOutputPerRun(): void
    {
        $line = $this->jobLineOfRuns(10)->setRequiredQuantity(50);

        self::assertSame([20, 50], [$line->getQuantityOfRuns(4), $line->getQuantityOfRuns(10)]);
    }

    #[Group('issue-1202')]
    public function testQuantityOfRunsOfAReactionLineMultipliesTheRunsByItsOutputPerRun(): void
    {
        $line = $this->jobLineOfRuns(5)->setActivityType('reaction')->setRequiredQuantity(1_000);

        self::assertSame(600, $line->getQuantityOfRuns(3));
    }

    #[Group('issue-1202')]
    public function testQuantityOfRunsOfACopyLineIsTheRunsWhateverItsRequiredQuantity(): void
    {
        $line = $this->jobLineOfRuns(8)->setActivityType('copying')->setRequiredQuantity(40);

        self::assertSame(3, $line->getQuantityOfRuns(3));
    }

    #[Group('issue-1202')]
    public function testQuantityOfRunsOfALineWithoutRunsIsRefused(): void
    {
        $line = $this->jobLineOfRuns(10)->setRuns(null);

        $this->expectException(\LogicException::class);

        $line->getQuantityOfRuns(4);
    }

    #[Group('issue-1202')]
    public function testQuantityOfRunsOfALineWithZeroRunsIsRefused(): void
    {
        $line = $this->jobLineOfRuns(10)->setRuns(0);

        $this->expectException(\LogicException::class);

        $line->getQuantityOfRuns(4);
    }

    #[Group('issue-1193')]
    public function testJobLinesOfTheFourActivitiesAreMatchableAndEachHasItsEsiJobActivityIds(): void
    {
        $activityTypes = ['manufacturing', 'reaction', 'copy', 'invention'];
        $lines = array_map(
            fn (string $activityType): IndustryProjectBomLine => $this->jobLineOfRuns(5)->setActivityType($activityType),
            $activityTypes,
        );

        self::assertSame(
            [true, true, true, true],
            array_map(static fn (IndustryProjectBomLine $line): bool => $line->isMatchableJob(), $lines),
        );
        self::assertSame(
            [[1], [9, 11], [5], [8]],
            array_map(static fn (IndustryProjectBomLine $line): array => $line->esiJobActivityIds(), $lines),
        );
    }

    #[Group('issue-1193')]
    public function testMaterialLineAndJobLineOfAnUnknownActivityAreNotMatchableJobs(): void
    {
        $materialLine = (new IndustryProjectBomLine())->setIsJob(false)->setActivityType('copy')->setRuns(5);
        $unknownActivityLine = $this->jobLineOfRuns(5)->setActivityType('research');
        $withoutActivityLine = $this->jobLineOfRuns(5)->setActivityType(null);

        self::assertSame(
            [false, false, false],
            [$materialLine->isMatchableJob(), $unknownActivityLine->isMatchableJob(), $withoutActivityLine->isMatchableJob()],
        );
    }

    #[Group('issue-1193')]
    public function testEsiJobActivityIdsOfALineOutsideTheFourActivitiesAreRefused(): void
    {
        $this->expectException(\LogicException::class);

        $this->jobLineOfRuns(5)->setActivityType('research')->esiJobActivityIds();
    }

    #[Group('issue-1193')]
    public function testCopyAndInventionLinesStayOutOfTheManufacturingOrReactionJobs(): void
    {
        self::assertSame(
            [false, false, true, true],
            [
                $this->jobLineOfRuns(5)->setActivityType('copy')->isManufacturingOrReactionJob(),
                $this->jobLineOfRuns(5)->setActivityType('invention')->isManufacturingOrReactionJob(),
                $this->jobLineOfRuns(5)->setActivityType('manufacturing')->isManufacturingOrReactionJob(),
                $this->jobLineOfRuns(5)->setActivityType('reaction')->isManufacturingOrReactionJob(),
            ],
        );
    }

    private function jobLineOfRuns(int $runs): IndustryProjectBomLine
    {
        return (new IndustryProjectBomLine())
            ->setTypeId(2_183)
            ->setTypeName('Hammerhead I')
            ->setIsJob(true)
            ->setActivityType('manufacturing')
            ->setRuns($runs)
            ->setRequiredQuantity($runs);
    }

    private function stepMark(BomLineMarkState $state, int $runs): IndustryProjectBomLineMark
    {
        return new IndustryProjectBomLineMark($state, $runs, new \DateTimeImmutable('2026-11-13T09:00:00+00:00'));
    }
}
