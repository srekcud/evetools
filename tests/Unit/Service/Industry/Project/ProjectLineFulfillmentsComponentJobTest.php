<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project;

use App\Entity\IndustryProjectBomLine;
use App\Service\Industry\Project\ContainerStockState;
use App\Service\Industry\Project\ProjectLineFulfillments;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProjectLineFulfillments::class)]
#[Group('issue-1013')]
final class ProjectLineFulfillmentsComponentJobTest extends TestCase
{
    private const int HYDROGEN_FUEL_BLOCK = 4246;

    public function testComponentJobLinePartlyCoveredByTheContainerOwesTheRest(): void
    {
        $fulfillments = new ProjectLineFulfillments(ContainerStockState::Known, [self::HYDROGEN_FUEL_BLOCK => 120]);
        $line = $this->jobLine('component', 300, 0);

        self::assertSame(120, $fulfillments->containerQuantityOf($line));
        self::assertSame(120, $fulfillments->fulfilledOf($line));
        self::assertSame(180, $fulfillments->remainingOf($line));
        self::assertFalse($fulfillments->isFulfilled($line));
        self::assertSame(ContainerStockState::Known, $fulfillments->stateOf($line));
    }

    public function testComponentJobLineCoveredByTheContainerAboveItsRequirementIsFulfilledAndCapped(): void
    {
        $fulfillments = new ProjectLineFulfillments(ContainerStockState::Known, [self::HYDROGEN_FUEL_BLOCK => 900]);
        $line = $this->jobLine('component', 300, 0);

        self::assertSame(300, $fulfillments->fulfilledOf($line));
        self::assertSame(0, $fulfillments->remainingOf($line));
        self::assertSame(100.0, $fulfillments->percentOf($line));
        self::assertTrue($fulfillments->isFulfilled($line));
    }

    public function testComponentJobLineAbsentFromAReadableContainerIsAKnownZero(): void
    {
        $fulfillments = new ProjectLineFulfillments(ContainerStockState::Known, [34 => 500]);
        $line = $this->jobLine('component', 300, 20);

        self::assertSame(0, $fulfillments->containerQuantityOf($line));
        self::assertSame(20, $fulfillments->fulfilledOf($line));
        self::assertSame(ContainerStockState::Known, $fulfillments->stateOf($line));
    }

    public function testComponentJobLineOfAnUnreadableContainerIsNeverCoveredAndShowsTheUnknownState(): void
    {
        $fulfillments = new ProjectLineFulfillments(ContainerStockState::Unknown);
        $line = $this->jobLine('component', 300, 20);

        self::assertNull($fulfillments->containerQuantityOf($line));
        self::assertSame(20, $fulfillments->fulfilledOf($line));
        self::assertSame(280, $fulfillments->remainingOf($line));
        self::assertSame(ContainerStockState::Unknown, $fulfillments->stateOf($line));
    }

    public function testComponentJobLineOfAProjectWithoutContainerKeepsTheNoneState(): void
    {
        $fulfillments = ProjectLineFulfillments::none();
        $line = $this->jobLine('component', 300, 20);

        self::assertNull($fulfillments->containerQuantityOf($line));
        self::assertSame(ContainerStockState::None, $fulfillments->stateOf($line));
    }

    public function testFinalAndBlueprintJobLinesKeepIgnoringTheContainer(): void
    {
        $fulfillments = new ProjectLineFulfillments(ContainerStockState::Known, [self::HYDROGEN_FUEL_BLOCK => 900]);

        foreach (['final', 'blueprint'] as $jobGroup) {
            $line = $this->jobLine($jobGroup, 300, 20);

            self::assertNull($fulfillments->containerQuantityOf($line), $jobGroup);
            self::assertSame(20, $fulfillments->fulfilledOf($line), $jobGroup);
            self::assertSame(ContainerStockState::None, $fulfillments->stateOf($line), $jobGroup);
        }
    }

    private function jobLine(string $jobGroup, int $required, int $fulfilled): IndustryProjectBomLine
    {
        return (new IndustryProjectBomLine())
            ->setTypeId(self::HYDROGEN_FUEL_BLOCK)
            ->setTypeName('Hydrogen Fuel Block')
            ->setRequiredQuantity($required)
            ->setFulfilledQuantity($fulfilled)
            ->setIsJob(true)
            ->setJobGroup($jobGroup)
            ->setActivityType('manufacturing');
    }
}
