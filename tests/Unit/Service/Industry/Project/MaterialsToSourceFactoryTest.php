<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project;

use App\Entity\IndustryProjectBomLine;
use App\Industry\Application\MaterialToSource;
use App\Industry\Domain\ActivityKind;
use App\Industry\Domain\Job;
use App\Industry\Domain\MaterialEfficiency;
use App\Industry\Domain\Plan;
use App\Industry\Domain\Quantity;
use App\Industry\Domain\Runs;
use App\Industry\Domain\TimeEfficiency;
use App\Service\Industry\Project\ContainerStockState;
use App\Service\Industry\Project\MaterialsToSourceFactory;
use App\Service\Industry\Project\ProjectLineFulfillments;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(MaterialsToSourceFactory::class)]
#[Group('issue-466')]
final class MaterialsToSourceFactoryTest extends TestCase
{
    private const int TRITANIUM = 34;
    private const int PYERITE = 35;
    private const int HYDROCARBONS = 16633;
    private const int MORPHITE = 11399;
    private const int RAM_STARSHIP_TECH = 11478;

    public function testEveryMaterialWithARemainingQuantityIsToSourceForThatRemainingQuantity(): void
    {
        $materials = MaterialsToSourceFactory::of(
            [$this->material(self::TRITANIUM, 12_000, 2_000), $this->material(self::PYERITE, 500, 0)],
            new Plan([], [], []),
        );

        self::assertEquals(
            [
                new MaterialToSource(self::TRITANIUM, new Quantity(10_000), ActivityKind::Manufacturing),
                new MaterialToSource(self::PYERITE, new Quantity(500), ActivityKind::Manufacturing),
            ],
            $materials,
        );
    }

    public function testFulfilledMaterialsAndJobLinesAreNotToSource(): void
    {
        $fulfilled = $this->material(self::TRITANIUM, 12_000, 12_000);
        $job = (new IndustryProjectBomLine())->setTypeId(self::RAM_STARSHIP_TECH)->setTypeName('R.A.M.- Starship Tech')->setRequiredQuantity(300)->setIsJob(true);

        self::assertSame([], MaterialsToSourceFactory::of([$fulfilled, $job], new Plan([], [], [])));
    }

    public function testMaterialConsumedOnlyByReactionsIsDeliveredToTheReactionWorkshop(): void
    {
        $plan = new Plan(
            [new Job(9999, ActivityKind::Reaction, new Runs(1), new MaterialEfficiency(0), new TimeEfficiency(0), [self::HYDROCARBONS => new Quantity(100)])],
            [],
            [],
        );

        $materials = MaterialsToSourceFactory::of(
            [$this->material(self::HYDROCARBONS, 100, 0), $this->material(self::MORPHITE, 150, 3)],
            $plan,
        );

        self::assertSame(
            [ActivityKind::Reaction, ActivityKind::Manufacturing],
            array_map(static fn (MaterialToSource $material): ActivityKind => $material->consumedBy, $materials),
        );
    }

    #[Group('issue-866')]
    public function testContainerStockReducesTheQuantityToSourceAndAFullyCoveredLineIsNotToSource(): void
    {
        $fulfillments = new ProjectLineFulfillments(ContainerStockState::Known, [self::RAM_STARSHIP_TECH => 2, self::TRITANIUM => 12_000]);

        $materials = MaterialsToSourceFactory::of(
            [$this->material(self::RAM_STARSHIP_TECH, 55, 0), $this->material(self::TRITANIUM, 12_000, 0), $this->material(self::PYERITE, 500, 0)],
            new Plan([], [], []),
            $fulfillments,
        );

        self::assertEquals(
            [
                new MaterialToSource(self::RAM_STARSHIP_TECH, new Quantity(53), ActivityKind::Manufacturing),
                new MaterialToSource(self::PYERITE, new Quantity(500), ActivityKind::Manufacturing),
            ],
            $materials,
        );
    }

    #[Group('issue-866')]
    public function testUnreadableContainerLeavesTheContributionsAsTheOnlyDeduction(): void
    {
        $materials = MaterialsToSourceFactory::of(
            [$this->material(self::TRITANIUM, 12_000, 2_000)],
            new Plan([], [], []),
            new ProjectLineFulfillments(ContainerStockState::Unknown),
        );

        self::assertEquals([new MaterialToSource(self::TRITANIUM, new Quantity(10_000), ActivityKind::Manufacturing)], $materials);
    }

    private function material(int $typeId, int $required, int $fulfilled): IndustryProjectBomLine
    {
        return (new IndustryProjectBomLine())
            ->setTypeId($typeId)
            ->setTypeName('Material '.$typeId)
            ->setRequiredQuantity($required)
            ->setFulfilledQuantity($fulfilled)
            ->setIsJob(false);
    }
}
