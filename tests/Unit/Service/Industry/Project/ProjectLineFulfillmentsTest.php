<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project;

use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectPurchase;
use App\Service\Industry\Project\ContainerStockState;
use App\Service\Industry\Project\ProjectLineFulfillments;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProjectLineFulfillments::class)]
#[Group('issue-866')]
final class ProjectLineFulfillmentsTest extends TestCase
{
    private const int RAM_STARSHIP_TECH = 11478;
    private const int TRITANIUM = 34;
    private const int CADMIUM = 16_647;
    private const int SILICON = 16_636;
    private const int HYDROGEN_FUEL_BLOCK = 4_246;
    private const int ZYDRINE = 39;
    private const int PHOTONIC_UNIT = 16_680;

    /**
     * @return iterable<string, array{int, int, int|null, int, int}>
     */
    public static function knownContainerCases(): iterable
    {
        yield 'issue case: 2 in the container, nothing contributed' => [55, 0, 2, 2, 53];
        yield 'contribution above the container keeps the contribution' => [55, 10, 2, 10, 45];
        yield 'container above the contribution wins' => [55, 10, 12, 12, 43];
        yield 'container above the requirement is capped' => [55, 10, 70, 55, 0];
        yield 'container without that type' => [55, 10, 0, 10, 45];
        yield 'container dropped back under the contribution' => [55, 10, 4, 10, 45];
    }

    #[DataProvider('knownContainerCases')]
    public function testFulfilledIsTheLargerOfTheContributionsAndTheCappedContainerStock(int $required, int $contributed, int $containerQuantity, int $expectedFulfilled, int $expectedRemaining): void
    {
        $fulfillments = new ProjectLineFulfillments(ContainerStockState::Known, [self::RAM_STARSHIP_TECH => $containerQuantity]);
        $line = $this->materialLine(self::RAM_STARSHIP_TECH, $required, $contributed);

        self::assertSame($expectedFulfilled, $fulfillments->fulfilledOf($line));
        self::assertSame($expectedRemaining, $fulfillments->remainingOf($line));
        self::assertSame($containerQuantity, $fulfillments->containerQuantityOf($line));
        self::assertSame(ContainerStockState::Known, $fulfillments->stateOf($line));
    }

    public function testTypeAbsentFromAReadableContainerIsAKnownZeroNotAnUnknown(): void
    {
        $fulfillments = new ProjectLineFulfillments(ContainerStockState::Known, [self::TRITANIUM => 500]);
        $line = $this->materialLine(self::RAM_STARSHIP_TECH, 55, 0);

        self::assertSame(0, $fulfillments->containerQuantityOf($line));
        self::assertSame(ContainerStockState::Known, $fulfillments->stateOf($line));
        self::assertSame(55, $fulfillments->remainingOf($line));
    }

    public function testPercentAndFulfilledFlagFollowTheEffectiveQuantity(): void
    {
        $fulfillments = new ProjectLineFulfillments(ContainerStockState::Known, [self::RAM_STARSHIP_TECH => 55, self::TRITANIUM => 10]);
        $coveredByContainer = $this->materialLine(self::RAM_STARSHIP_TECH, 55, 0);
        $partlyCovered = $this->materialLine(self::TRITANIUM, 40, 0);

        self::assertTrue($fulfillments->isFulfilled($coveredByContainer));
        self::assertSame(100.0, $fulfillments->percentOf($coveredByContainer));
        self::assertFalse($fulfillments->isFulfilled($partlyCovered));
        self::assertSame(25.0, $fulfillments->percentOf($partlyCovered));
    }

    public function testUnreadableContainerLeavesOnlyTheContributionsAsALowerBoundAndNeverAZeroQuantity(): void
    {
        $fulfillments = new ProjectLineFulfillments(ContainerStockState::Unknown);
        $line = $this->materialLine(self::RAM_STARSHIP_TECH, 55, 10);

        self::assertSame(10, $fulfillments->fulfilledOf($line));
        self::assertSame(45, $fulfillments->remainingOf($line));
        self::assertNull($fulfillments->containerQuantityOf($line));
        self::assertSame(ContainerStockState::Unknown, $fulfillments->stateOf($line));
    }

    public function testProjectWithoutContainerNameKeepsTheCurrentBehaviour(): void
    {
        $fulfillments = ProjectLineFulfillments::none();
        $line = $this->materialLine(self::RAM_STARSHIP_TECH, 55, 10);

        self::assertSame(10, $fulfillments->fulfilledOf($line));
        self::assertSame(45, $fulfillments->remainingOf($line));
        self::assertNull($fulfillments->containerQuantityOf($line));
        self::assertSame(ContainerStockState::None, $fulfillments->stateOf($line));
    }

    public function testJobLinesKeepThePersistedQuantityWhateverTheContainerHolds(): void
    {
        $fulfillments = new ProjectLineFulfillments(ContainerStockState::Known, [self::RAM_STARSHIP_TECH => 300]);
        $job = $this->materialLine(self::RAM_STARSHIP_TECH, 300, 20)->setIsJob(true);

        self::assertSame(20, $fulfillments->fulfilledOf($job));
        self::assertSame(280, $fulfillments->remainingOf($job));
        self::assertNull($fulfillments->containerQuantityOf($job));
        self::assertSame(ContainerStockState::None, $fulfillments->stateOf($job));
    }

    public static function consumedByStartedJobsCases(): iterable
    {
        yield 'issue 1140 case: cadmium consumed by a started reaction is still covered' => [self::CADMIUM, 38_200, 0, 34_987, 3_213, 38_200, 0];
        yield 'consumed quantity completes a partly covered line' => [self::SILICON, 1_000, 0, 100, 400, 500, 500];
        yield 'container plus consumed above the requirement is capped' => [self::HYDROGEN_FUEL_BLOCK, 1_000, 0, 900, 300, 1_000, 0];
        yield 'contribution above container plus consumed wins' => [self::ZYDRINE, 1_000, 700, 100, 200, 700, 300];
        yield 'nothing in the container and nothing consumed' => [self::PHOTONIC_UNIT, 1_000, 0, 0, 0, 0, 1_000];
    }

    #[Group('issue-1140')]
    #[DataProvider('consumedByStartedJobsCases')]
    public function testFulfilledAddsTheQuantityConsumedByStartedJobsToTheContainerStockBeforeTheCap(int $typeId, int $required, int $contributed, int $containerQuantity, int $consumedQuantity, int $expectedFulfilled, int $expectedRemaining): void
    {
        $fulfillments = new ProjectLineFulfillments(ContainerStockState::Known, [$typeId => $containerQuantity], [$typeId => $consumedQuantity]);
        $line = $this->materialLine($typeId, $required, $contributed);

        self::assertSame($expectedFulfilled, $fulfillments->fulfilledOf($line));
        self::assertSame($expectedRemaining, $fulfillments->remainingOf($line));
        self::assertSame($containerQuantity, $fulfillments->containerQuantityOf($line));
    }

    #[Group('issue-1140')]
    public function testConsumptionOfAnotherTypeDoesNotCoverTheLine(): void
    {
        $fulfillments = new ProjectLineFulfillments(ContainerStockState::Known, [self::CADMIUM => 100], [self::SILICON => 400]);
        $line = $this->materialLine(self::CADMIUM, 1_000, 0);

        self::assertSame(100, $fulfillments->fulfilledOf($line));
        self::assertSame(900, $fulfillments->remainingOf($line));
    }

    #[Group('issue-1140')]
    public function testConsumedQuantityIsIgnoredWhenTheContainerStockIsUnreadable(): void
    {
        $fulfillments = new ProjectLineFulfillments(ContainerStockState::Unknown, [], [self::CADMIUM => 3_213]);
        $line = $this->materialLine(self::CADMIUM, 38_200, 500);

        self::assertSame(500, $fulfillments->fulfilledOf($line));
        self::assertSame(37_700, $fulfillments->remainingOf($line));
        self::assertNull($fulfillments->containerQuantityOf($line));
    }

    #[Group('issue-1140')]
    public function testJobLineIsLeftUnchangedByTheConsumptionOfItsType(): void
    {
        $fulfillments = new ProjectLineFulfillments(ContainerStockState::Known, [self::CADMIUM => 300], [self::CADMIUM => 3_213]);
        $job = $this->materialLine(self::CADMIUM, 1_000, 20)->setIsJob(true)->setJobGroup('final')->setActivityType('manufacturing');

        self::assertSame(20, $fulfillments->fulfilledOf($job));
        self::assertSame(980, $fulfillments->remainingOf($job));
        self::assertNull($fulfillments->containerQuantityOf($job));
    }

    private function materialLine(int $typeId, int $required, int $contributed): IndustryProjectBomLine
    {
        return (new IndustryProjectBomLine())
            ->setTypeId($typeId)
            ->setTypeName('Material '.$typeId)
            ->setRequiredQuantity($required)
            ->setFulfilledQuantity($contributed)
            ->setIsJob(false);
    }

    #[Group('issue-1085')]
    public function testTheStockStateIsExposed(): void
    {
        self::assertSame(
            [ContainerStockState::Known, ContainerStockState::Unknown, ContainerStockState::None],
            [
                (new ProjectLineFulfillments(ContainerStockState::Known))->state(),
                (new ProjectLineFulfillments(ContainerStockState::Unknown))->state(),
                ProjectLineFulfillments::none()->state(),
            ],
        );
    }

    public function testOnlyAReadableContainerStockIsKnown(): void
    {
        self::assertSame(
            [true, false, false],
            [
                (new ProjectLineFulfillments(ContainerStockState::Known, [self::TRITANIUM => 500]))->isKnown(),
                (new ProjectLineFulfillments(ContainerStockState::Unknown))->isKnown(),
                ProjectLineFulfillments::none()->isKnown(),
            ],
        );
    }

    /**
     * @return iterable<string, array{list<int>, int|null, int|null}>
     */
    public static function purchasedQuantityCases(): iterable
    {
        yield 'nothing delivered' => [[600, 400], 0, 1_000];
        yield 'partly delivered' => [[600, 400], 700, 300];
        yield 'fully delivered' => [[600, 400], 1_000, 0];
        yield 'delivered above the purchases' => [[600, 400], 2_500, 0];
        yield 'purchase of quantity zero is ignored' => [[0], 0, 0];
    }

    /**
     * @param list<int> $purchasedQuantities
     */
    #[Group('issue-1175')]
    #[DataProvider('purchasedQuantityCases')]
    public function testMissingPurchasedQuantityIsWhatTheContainerDoesNotHoldOfThePurchases(array $purchasedQuantities, int $containerQuantity, int $expectedMissing): void
    {
        $fulfillments = new ProjectLineFulfillments(ContainerStockState::Known, [self::TRITANIUM => $containerQuantity]);

        self::assertSame($expectedMissing, $fulfillments->missingPurchasedQuantityOf($this->purchasedLine($purchasedQuantities)));
    }

    #[Group('issue-1175')]
    public function testMissingPurchasedQuantityIsUnknownWithoutAReadableContainer(): void
    {
        $line = $this->purchasedLine([1_000]);

        self::assertSame(
            [null, null],
            [
                (new ProjectLineFulfillments(ContainerStockState::Unknown))->missingPurchasedQuantityOf($line),
                ProjectLineFulfillments::none()->missingPurchasedQuantityOf($line),
            ],
        );
    }

    /**
     * @param list<int> $purchasedQuantities
     */
    private function purchasedLine(array $purchasedQuantities): IndustryProjectBomLine
    {
        $line = $this->materialLine(self::TRITANIUM, array_sum($purchasedQuantities), 0);
        foreach ($purchasedQuantities as $quantity) {
            $line->addPurchase((new IndustryProjectPurchase())->setTypeId(self::TRITANIUM)->setQuantity($quantity)->setUnitPrice(1.0)->setTotalPrice((float) $quantity)->setSource('manual'));
        }

        return $line;
    }
}
