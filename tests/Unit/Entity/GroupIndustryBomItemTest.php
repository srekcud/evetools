<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\GroupIndustryBomItem;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(GroupIndustryBomItem::class)]
#[Group('issue-107')]
final class GroupIndustryBomItemTest extends TestCase
{
    public function testJobLineOfOneRunIsFulfilledByTheUnitsProducedByThatRun(): void
    {
        $finalJob = (new GroupIndustryBomItem())
            ->setIsJob(true)
            ->setActivityType('manufacturing')
            ->setRuns(1)
            ->setRequiredQuantity(40)
            ->setFulfilledQuantity(40);

        self::assertSame([true, 0, 100.0], [$finalJob->isFulfilled(), $finalJob->getRemainingQuantity(), $finalJob->getFulfillmentPercent()]);
    }

    public function testJobLineOfOneRunIsNotFulfilledByASingleUnit(): void
    {
        $finalJob = (new GroupIndustryBomItem())
            ->setIsJob(true)
            ->setActivityType('manufacturing')
            ->setRuns(1)
            ->setRequiredQuantity(40)
            ->setFulfilledQuantity(1);

        self::assertSame([false, 39, 2.5], [$finalJob->isFulfilled(), $finalJob->getRemainingQuantity(), $finalJob->getFulfillmentPercent()]);
    }
}
