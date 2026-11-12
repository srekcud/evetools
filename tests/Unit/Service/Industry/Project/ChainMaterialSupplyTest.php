<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project;

use App\Entity\CachedIndustryJob;
use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectJobMatch;
use App\Service\Industry\Project\ChainManufacturingLevels;
use App\Service\Industry\Project\ChainMaterialSupply;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChainMaterialSupply::class)]
#[Group('issue-449')]
final class ChainMaterialSupplyTest extends TestCase
{
    use BuildsChainBomLines;

    private const int TRITANIUM = 34;
    private const int PYERITE = 35;
    private const int PRODUCT = 449_101;
    private const int COMPONENT = 449_102;
    private const int REACTION = 449_103;
    private const int ALPHA_PART = 449_104;
    private const int ZULU_PART = 449_105;

    public function testJobIsSuppliedWhenItsMaterialIsFullyFulfilled(): void
    {
        $component = $this->jobLine(self::COMPONENT, 'Component', 'component', null);
        $tritanium = $this->materialLine(self::TRITANIUM, 'Tritanium', 100, 100);

        $supply = $this->supplyOf([$component, $tritanium], [self::lineId($component) => [self::TRITANIUM => 100]]);

        self::assertTrue($supply->isSupplied(self::lineId($component)));
    }

    public function testJobIsNotSuppliedWhenOneUnitIsMissing(): void
    {
        $component = $this->jobLine(self::COMPONENT, 'Component', 'component', null);
        $tritanium = $this->materialLine(self::TRITANIUM, 'Tritanium', 100, 99);

        $supply = $this->supplyOf([$component, $tritanium], [self::lineId($component) => [self::TRITANIUM => 100]]);

        self::assertFalse($supply->isSupplied(self::lineId($component)));
    }

    public function testJobIsNotSuppliedWhenOnlyOneOfItsMaterialsIsCovered(): void
    {
        $component = $this->jobLine(self::COMPONENT, 'Component', 'component', null);
        $tritanium = $this->materialLine(self::TRITANIUM, 'Tritanium', 100, 100);
        $pyerite = $this->materialLine(self::PYERITE, 'Pyerite', 50, 49);

        $supply = $this->supplyOf([$component, $tritanium, $pyerite], [self::lineId($component) => [self::TRITANIUM => 100, self::PYERITE => 50]]);

        self::assertFalse($supply->isSupplied(self::lineId($component)));
    }

    public function testFulfilledQuantityGoesToTheLowestLevelJobFirst(): void
    {
        $reaction = $this->jobLine(self::REACTION, 'Reaction', 'component', self::COMPONENT, activityType: 'reaction');
        $component = $this->jobLine(self::COMPONENT, 'Component', 'component', null);
        $tritanium = $this->materialLine(self::TRITANIUM, 'Tritanium', 200, 150);

        $supply = $this->supplyOf(
            [$component, $reaction, $tritanium],
            [self::lineId($reaction) => [self::TRITANIUM => 100], self::lineId($component) => [self::TRITANIUM => 100]],
        );

        self::assertSame([true, false], [$supply->isSupplied(self::lineId($reaction)), $supply->isSupplied(self::lineId($component))]);
    }

    public function testJobsOfTheSameLevelAreServedByTypeName(): void
    {
        $zuluPart = $this->jobLine(self::ZULU_PART, 'Zulu part', 'component', null);
        $alphaPart = $this->jobLine(self::ALPHA_PART, 'Alpha part', 'component', null);
        $tritanium = $this->materialLine(self::TRITANIUM, 'Tritanium', 200, 100);

        $supply = $this->supplyOf(
            [$zuluPart, $alphaPart, $tritanium],
            [self::lineId($zuluPart) => [self::TRITANIUM => 100], self::lineId($alphaPart) => [self::TRITANIUM => 100]],
        );

        self::assertSame([false, true], [$supply->isSupplied(self::lineId($zuluPart)), $supply->isSupplied(self::lineId($alphaPart))]);
    }

    public function testNeedForATypeWithoutAnyBomLineIsIgnored(): void
    {
        $product = $this->jobLine(self::PRODUCT, 'Product', 'final', null);
        $tritanium = $this->materialLine(self::TRITANIUM, 'Tritanium', 100, 100);

        $supply = $this->supplyOf([$product, $tritanium], [self::lineId($product) => [self::ZULU_PART => 5, self::TRITANIUM => 100]]);

        self::assertTrue($supply->isSupplied(self::lineId($product)));
    }

    #[Group('issue-707')]
    public function testJobIsNotSuppliedWhenTheJobProducingItsIntermediateDeliveredNothing(): void
    {
        $product = $this->jobLine(self::PRODUCT, 'Product', 'final', null);
        $component = $this->jobLine(self::COMPONENT, 'Component', 'component', self::PRODUCT);

        $supply = $this->supplyOf([$product, $component], [self::lineId($product) => [self::COMPONENT => 5], self::lineId($component) => []]);

        self::assertFalse($supply->isSupplied(self::lineId($product)));
        self::assertTrue($supply->isSupplied(self::lineId($component)));
    }

    #[Group('issue-707')]
    public function testJobIsSuppliedWhenTheJobProducingItsIntermediateDeliveredInFull(): void
    {
        $product = $this->jobLine(self::PRODUCT, 'Product', 'final', null);
        $component = $this->jobLine(self::COMPONENT, 'Component', 'component', self::PRODUCT);
        $this->linkJob($component, CachedIndustryJob::STATUS_DELIVERED, 10);

        $supply = $this->supplyOf([$product, $component], [self::lineId($product) => [self::COMPONENT => 5], self::lineId($component) => []]);

        self::assertTrue($supply->isSupplied(self::lineId($product)));
    }

    #[Group('issue-707')]
    public function testJobIsNotSuppliedWhenTheIntermediateIsOnlyPartiallyDelivered(): void
    {
        $product = $this->jobLine(self::PRODUCT, 'Product', 'final', null);
        $component = $this->jobLine(self::COMPONENT, 'Component', 'component', self::PRODUCT);
        $this->linkJob($component, CachedIndustryJob::STATUS_DELIVERED, 4);

        $supply = $this->supplyOf([$product, $component], [self::lineId($product) => [self::COMPONENT => 5], self::lineId($component) => []]);

        self::assertFalse($supply->isSupplied(self::lineId($product)));
    }

    #[Group('issue-707')]
    public function testJobStillRunningDoesNotSupplyItsIntermediate(): void
    {
        $product = $this->jobLine(self::PRODUCT, 'Product', 'final', null);
        $component = $this->jobLine(self::COMPONENT, 'Component', 'component', self::PRODUCT);
        $this->linkJob($component, CachedIndustryJob::STATUS_ACTIVE, 10);

        $supply = $this->supplyOf([$product, $component], [self::lineId($product) => [self::COMPONENT => 5], self::lineId($component) => []]);

        self::assertFalse($supply->isSupplied(self::lineId($product)));
    }

    #[Group('issue-707')]
    public function testIntermediateContributionAndDeliveredProductionAddUp(): void
    {
        $component = $this->jobLine(self::COMPONENT, 'Component', 'component', self::PRODUCT, runs: 6)->setRequiredQuantity(10)->setFulfilledQuantity(4);
        $this->linkJob($component, CachedIndustryJob::STATUS_DELIVERED, 3);
        $coveredByThree = $this->jobLine(self::PRODUCT, 'Product', 'final', null);
        $coveredByFour = $this->jobLine(self::ZULU_PART, 'Zulu part', 'final', null);

        $supplyOfSeven = $this->supplyOf([$coveredByThree, $component], [self::lineId($coveredByThree) => [self::COMPONENT => 7], self::lineId($component) => []]);
        $supplyOfEight = $this->supplyOf([$coveredByFour, $component], [self::lineId($coveredByFour) => [self::COMPONENT => 8], self::lineId($component) => []]);

        self::assertTrue($supplyOfSeven->isSupplied(self::lineId($coveredByThree)));
        self::assertFalse($supplyOfEight->isSupplied(self::lineId($coveredByFour)));
    }

    #[Group('issue-707')]
    public function testLineCoveredByStockSuppliesItsContributionWithoutDividingByZeroRuns(): void
    {
        $product = $this->jobLine(self::PRODUCT, 'Product', 'final', null);
        $component = $this->jobLine(self::COMPONENT, 'Component', 'component', self::PRODUCT, runs: 0)->setRequiredQuantity(5)->setFulfilledQuantity(5);

        $supply = $this->supplyOf([$product, $component], [self::lineId($product) => [self::COMPONENT => 5]]);

        self::assertTrue($supply->isSupplied(self::lineId($product)));
    }

    #[Group('issue-707')]
    public function testDeliveredIntermediateIsSharedBetweenItsConsumers(): void
    {
        $zuluPart = $this->jobLine(self::ZULU_PART, 'Zulu part', 'final', null);
        $alphaPart = $this->jobLine(self::ALPHA_PART, 'Alpha part', 'final', null);
        $component = $this->jobLine(self::COMPONENT, 'Component', 'component', self::PYERITE);
        $this->linkJob($component, CachedIndustryJob::STATUS_DELIVERED, 10);

        $supply = $this->supplyOf(
            [$zuluPart, $alphaPart, $component],
            [self::lineId($zuluPart) => [self::COMPONENT => 6], self::lineId($alphaPart) => [self::COMPONENT => 6], self::lineId($component) => []],
        );

        self::assertSame([false, true], [$supply->isSupplied(self::lineId($zuluPart)), $supply->isSupplied(self::lineId($alphaPart))]);
    }

    public function testSupplyIsUnknownWhenThePlanCouldNotBeComputed(): void
    {
        $component = $this->jobLine(self::COMPONENT, 'Component', 'component', null);
        $tritanium = $this->materialLine(self::TRITANIUM, 'Tritanium', 100, 100);

        $supply = $this->supplyOf([$component, $tritanium], null);

        self::assertNull($supply->isSupplied(self::lineId($component)));
        self::assertNull($supply->firstConsumerLevel(self::TRITANIUM));
    }

    public function testSupplyIsUnknownForAJobTheMapperCouldNotMatch(): void
    {
        $matched = $this->jobLine(self::COMPONENT, 'Component', 'component', null);
        $unmatched = $this->jobLine(self::REACTION, 'Reaction', 'component', self::COMPONENT, activityType: 'reaction');
        $tritanium = $this->materialLine(self::TRITANIUM, 'Tritanium', 100, 100);

        $supply = $this->supplyOf([$matched, $unmatched, $tritanium], [self::lineId($matched) => [self::TRITANIUM => 100]]);

        self::assertTrue($supply->isSupplied(self::lineId($matched)));
        self::assertNull($supply->isSupplied(self::lineId($unmatched)));
    }

    public function testCopyLineHasNoSupplyVerdict(): void
    {
        $copy = $this->jobLine(self::COMPONENT, 'Component', 'blueprint', null, activityType: 'copy');

        $supply = $this->supplyOf([$copy], []);

        self::assertNull($supply->isSupplied(self::lineId($copy)));
    }

    public function testMaterialLevelIsTheLowestLevelAmongItsConsumers(): void
    {
        $component = $this->jobLine(self::COMPONENT, 'Component', 'component', self::PRODUCT);
        $product = $this->jobLine(self::PRODUCT, 'Product', 'final', null);
        $tritanium = $this->materialLine(self::TRITANIUM, 'Tritanium', 200, 0);
        $pyerite = $this->materialLine(self::PYERITE, 'Pyerite', 100, 0);

        $supply = $this->supplyOf(
            [$product, $component, $tritanium, $pyerite],
            [self::lineId($product) => [self::TRITANIUM => 100, self::PYERITE => 100], self::lineId($component) => [self::TRITANIUM => 100]],
        );

        self::assertSame([1, 2], [$supply->firstConsumerLevel(self::TRITANIUM), $supply->firstConsumerLevel(self::PYERITE)]);
        self::assertNull($supply->firstConsumerLevel(self::ZULU_PART));
    }

    private function linkJob(IndustryProjectBomLine $line, string $status, int $runs): void
    {
        $line->addJobMatch((new IndustryProjectJobMatch())->setStatus($status)->setRuns($runs));
    }

    /**
     * @param array<IndustryProjectBomLine>           $lines
     * @param array<string, array<int, int>>|null     $needs
     */
    private function supplyOf(array $lines, ?array $needs): ChainMaterialSupply
    {
        return ChainMaterialSupply::allocate($lines, ChainManufacturingLevels::byTypeId($lines), $needs);
    }
}
