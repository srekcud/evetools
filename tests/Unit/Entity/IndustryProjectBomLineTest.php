<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\IndustryProjectBomLine;
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
}
