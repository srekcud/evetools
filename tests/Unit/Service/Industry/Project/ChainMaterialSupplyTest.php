<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project;

use App\Entity\IndustryProjectBomLine;
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

    public function testNeedForAMaterialThatIsNotABomMaterialLineIsIgnored(): void
    {
        $product = $this->jobLine(self::PRODUCT, 'Product', 'final', null);
        $component = $this->jobLine(self::COMPONENT, 'Component', 'component', self::PRODUCT);
        $tritanium = $this->materialLine(self::TRITANIUM, 'Tritanium', 100, 100);

        $supply = $this->supplyOf(
            [$product, $component, $tritanium],
            [self::lineId($product) => [self::COMPONENT => 5, self::TRITANIUM => 100], self::lineId($component) => []],
        );

        self::assertTrue($supply->isSupplied(self::lineId($product)));
        self::assertTrue($supply->isSupplied(self::lineId($component)));
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

    /**
     * @param array<IndustryProjectBomLine>           $lines
     * @param array<string, array<int, int>>|null     $needs
     */
    private function supplyOf(array $lines, ?array $needs): ChainMaterialSupply
    {
        return ChainMaterialSupply::allocate($lines, ChainManufacturingLevels::byTypeId($lines), $needs);
    }
}
