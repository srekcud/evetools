<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project;

use App\Entity\IndustryProjectBomLine;
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

    private function materialLine(int $typeId, int $required, int $contributed): IndustryProjectBomLine
    {
        return (new IndustryProjectBomLine())
            ->setTypeId($typeId)
            ->setTypeName('Material '.$typeId)
            ->setRequiredQuantity($required)
            ->setFulfilledQuantity($contributed)
            ->setIsJob(false);
    }
}
