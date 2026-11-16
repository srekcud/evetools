<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;
use App\Entity\User;
use App\Enum\ProjectStatus;
use App\Repository\IndustryProjectContributionRepository;
use App\Service\Industry\Project\ContainerStockState;
use App\Service\Industry\Project\ProjectContainerFulfillment;
use App\Service\Industry\Project\ProjectLineFulfillments;
use App\Service\Industry\Project\UnclaimedStockLine;
use App\Service\Industry\Project\UnclaimedStockLines;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(UnclaimedStockLines::class)]
#[Group('issue-1051')]
final class UnclaimedStockLinesTest extends TestCase
{
    private const int TRITANIUM_TYPE_ID = 34;
    private const int PYERITE_TYPE_ID = 35;
    private const int MEXALLON_TYPE_ID = 36;
    private const int HAMMERHEAD_TYPE_ID = 2185;

    public function testLinesWithUnclaimedStockAreListedWithTheirUnclaimedQuantity(): void
    {
        [$lines, $project, $tritanium] = $this->linesOf(ProjectStatus::InProgress, new ProjectLineFulfillments(ContainerStockState::Known, [self::TRITANIUM_TYPE_ID => 80, self::PYERITE_TYPE_ID => 40, self::MEXALLON_TYPE_ID => 0]), 30);

        $unclaimed = $lines->of($project);

        self::assertCount(1, $unclaimed);
        self::assertInstanceOf(UnclaimedStockLine::class, $unclaimed[0]);
        self::assertSame([$tritanium->getId()->toRfc4122(), self::TRITANIUM_TYPE_ID, 'Tritanium', 50], [$unclaimed[0]->bomLineId, $unclaimed[0]->typeId, $unclaimed[0]->typeName, $unclaimed[0]->quantity]);
    }

    public function testSellingProjectStillListsItsUnclaimedStock(): void
    {
        [$lines, $project] = $this->linesOf(ProjectStatus::Selling, new ProjectLineFulfillments(ContainerStockState::Known, [self::TRITANIUM_TYPE_ID => 80]), 0);

        self::assertCount(1, $lines->of($project));
    }

    public function testNothingIsListedOutsideTheInProgressAndSellingStatuses(): void
    {
        foreach ([ProjectStatus::Draft, ProjectStatus::Published, ProjectStatus::Completed] as $status) {
            [$lines, $project] = $this->linesOf($status, new ProjectLineFulfillments(ContainerStockState::Known, [self::TRITANIUM_TYPE_ID => 80]), 0);

            self::assertSame([], $lines->of($project), $status->value);
        }
    }

    public function testNothingIsListedWhenTheContainerStockIsUnknownOrThereIsNoContainer(): void
    {
        foreach ([new ProjectLineFulfillments(ContainerStockState::Unknown), ProjectLineFulfillments::none()] as $fulfillments) {
            [$lines, $project] = $this->linesOf(ProjectStatus::InProgress, $fulfillments, 0);

            self::assertSame([], $lines->of($project));
        }
    }

    public function testProductionJobsNeverCarryUnclaimedStock(): void
    {
        [$lines, $project] = $this->linesOf(ProjectStatus::InProgress, new ProjectLineFulfillments(ContainerStockState::Known, [self::HAMMERHEAD_TYPE_ID => 100]), 0, false);

        self::assertSame([], $lines->of($project));
    }

    public function testContainerStockIsNotReadOutsideTheInProgressAndSellingStatuses(): void
    {
        $containerFulfillment = $this->createMock(ProjectContainerFulfillment::class);
        $containerFulfillment->expects(self::never())->method('of');
        $contributions = $this->createMock(IndustryProjectContributionRepository::class);
        $contributions->expects(self::never())->method('sumClaimedMaterialQuantityByLine');
        $project = (new IndustryProject())->setOwner(new User())->setStatus(ProjectStatus::Completed);

        self::assertSame([], (new UnclaimedStockLines($containerFulfillment, $contributions))->of($project));
    }

    public function testClaimedQuantitiesAreNotReadWithoutAReadableContainerStock(): void
    {
        $containerFulfillment = $this->createStub(ProjectContainerFulfillment::class);
        $containerFulfillment->method('of')->willReturn(ProjectLineFulfillments::none());
        $contributions = $this->createMock(IndustryProjectContributionRepository::class);
        $contributions->expects(self::never())->method('sumClaimedMaterialQuantityByLine');
        $project = (new IndustryProject())->setOwner(new User())->setStatus(ProjectStatus::InProgress);

        self::assertSame([], (new UnclaimedStockLines($containerFulfillment, $contributions))->of($project));
    }

    /**
     * @return array{UnclaimedStockLines, IndustryProject, IndustryProjectBomLine}
     */
    private function linesOf(ProjectStatus $status, ProjectLineFulfillments $fulfillments, int $claimedQuantityOfTritanium, bool $withMaterials = true): array
    {
        $project = (new IndustryProject())->setOwner(new User())->setStatus($status);
        $tritanium = $this->materialLine(self::TRITANIUM_TYPE_ID, 'Tritanium', 100);
        $pyerite = $this->materialLine(self::PYERITE_TYPE_ID, 'Pyerite', 40);
        $mexallon = $this->materialLine(self::MEXALLON_TYPE_ID, 'Mexallon', 10);
        $hammerhead = (new IndustryProjectBomLine())->setTypeId(self::HAMMERHEAD_TYPE_ID)->setTypeName('Hammerhead II')->setRequiredQuantity(100)->setIsJob(true);
        foreach ($withMaterials ? [$tritanium, $pyerite, $mexallon, $hammerhead] : [$hammerhead] as $line) {
            $project->addBomItem($line);
        }

        $containerFulfillment = $this->createStub(ProjectContainerFulfillment::class);
        $containerFulfillment->method('of')->willReturn($fulfillments);
        $contributions = $this->createStub(IndustryProjectContributionRepository::class);
        $contributions->method('sumClaimedMaterialQuantityByLine')->willReturn([
            $tritanium->getId()->toRfc4122() => $claimedQuantityOfTritanium,
            $pyerite->getId()->toRfc4122() => 40,
        ]);

        return [new UnclaimedStockLines($containerFulfillment, $contributions), $project, $tritanium];
    }

    private function materialLine(int $typeId, string $typeName, int $requiredQuantity): IndustryProjectBomLine
    {
        return (new IndustryProjectBomLine())->setTypeId($typeId)->setTypeName($typeName)->setRequiredQuantity($requiredQuantity)->setIsJob(false);
    }
}
