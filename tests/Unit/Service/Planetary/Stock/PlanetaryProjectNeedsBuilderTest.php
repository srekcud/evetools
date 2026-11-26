<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Planetary\Stock;

use App\Service\Planetary\Stock\PlanetaryProjectNeed;
use App\Service\Planetary\Stock\PlanetaryProjectNeedsBuilder;
use App\Service\Planetary\Stock\ProjectLineDemand;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(PlanetaryProjectNeedsBuilder::class)]
#[Group('issue-1398')]
final class PlanetaryProjectNeedsBuilderTest extends TestCase
{
    private const string PROJECT_A = '0193a000-0000-7000-8000-00000000139a';
    private const string PROJECT_B = '0193a000-0000-7000-8000-00000000139b';
    private const string PROJECT_C = '0193a000-0000-7000-8000-00000000139c';

    private const int BROADCAST_NODE = 2867;
    private const int ROBOTICS = 9848;
    private const int COOLANT = 9832;
    private const int BACTERIA = 2393;

    public function testRemainingQuantitiesOfOneTypeAreSummedAcrossProjectsAndEachProjectKeepsItsOwnRemaining(): void
    {
        $needs = (new PlanetaryProjectNeedsBuilder())->build([
            $this->demand(self::PROJECT_A, 'Ishtar fleet', self::BROADCAST_NODE, 'Broadcast Node', 6),
            $this->demand(self::PROJECT_B, 'Rattlesnake batch', self::BROADCAST_NODE, 'Broadcast Node', 5),
        ], [self::BROADCAST_NODE => 7]);

        self::assertCount(1, $needs);
        self::assertSame(
            [self::BROADCAST_NODE, 'Broadcast Node', 11, 7, 4],
            [$needs[0]->typeId, $needs[0]->typeName, $needs[0]->required, $needs[0]->stock, $needs[0]->missing],
        );
        self::assertSame(
            [
                ['projectId' => self::PROJECT_A, 'projectName' => 'Ishtar fleet', 'remaining' => 6],
                ['projectId' => self::PROJECT_B, 'projectName' => 'Rattlesnake batch', 'remaining' => 5],
            ],
            $needs[0]->projects,
        );
    }

    public function testStockBelowEqualToAboveAndAbsentFromTheStockEachGiveTheirOwnMissingQuantityNeverNegative(): void
    {
        $needs = (new PlanetaryProjectNeedsBuilder())->build([
            $this->demand(self::PROJECT_A, 'Ishtar fleet', self::BROADCAST_NODE, 'Broadcast Node', 11),
            $this->demand(self::PROJECT_A, 'Ishtar fleet', self::ROBOTICS, 'Robotics', 5),
            $this->demand(self::PROJECT_A, 'Ishtar fleet', self::COOLANT, 'Coolant', 8),
            $this->demand(self::PROJECT_A, 'Ishtar fleet', self::BACTERIA, 'Bacteria', 1),
        ], [self::BROADCAST_NODE => 7, self::ROBOTICS => 5, self::COOLANT => 20]);

        self::assertSame(
            [
                [self::BACTERIA, 1, 0, 1],
                [self::BROADCAST_NODE, 11, 7, 4],
                [self::COOLANT, 8, 20, 0],
                [self::ROBOTICS, 5, 5, 0],
            ],
            array_map(static fn (PlanetaryProjectNeed $need): array => [$need->typeId, $need->required, $need->stock, $need->missing], $needs),
        );
    }

    public function testAnUnknownStockGivesNullStockAndNullMissingForEveryProductWhileTheRequiredQuantityStaysKnown(): void
    {
        $needs = (new PlanetaryProjectNeedsBuilder())->build([
            $this->demand(self::PROJECT_A, 'Ishtar fleet', self::BROADCAST_NODE, 'Broadcast Node', 11),
            $this->demand(self::PROJECT_B, 'Rattlesnake batch', self::ROBOTICS, 'Robotics', 3),
        ], null);

        self::assertSame(
            [
                [self::BROADCAST_NODE, 11, null, null],
                [self::ROBOTICS, 3, null, null],
            ],
            array_map(static fn (PlanetaryProjectNeed $need): array => [$need->typeId, $need->required, $need->stock, $need->missing], $needs),
        );
    }

    public function testAKnownEmptyStockIsZeroAndMissingEqualsRequiredNotUnknown(): void
    {
        $needs = (new PlanetaryProjectNeedsBuilder())->build([
            $this->demand(self::PROJECT_A, 'Ishtar fleet', self::COOLANT, 'Coolant', 8),
        ], []);

        self::assertCount(1, $needs);
        self::assertSame([8, 0, 8], [$needs[0]->required, $needs[0]->stock, $needs[0]->missing]);
    }

    public function testTwoLinesOfTheSameTypeInOneProjectAreAddedIntoASingleProjectEntry(): void
    {
        $needs = (new PlanetaryProjectNeedsBuilder())->build([
            $this->demand(self::PROJECT_A, 'Ishtar fleet', self::ROBOTICS, 'Robotics', 3),
            $this->demand(self::PROJECT_A, 'Ishtar fleet', self::ROBOTICS, 'Robotics', 2),
            $this->demand(self::PROJECT_B, 'Rattlesnake batch', self::ROBOTICS, 'Robotics', 4),
        ], [self::ROBOTICS => 1]);

        self::assertCount(1, $needs);
        self::assertSame([9, 1, 8], [$needs[0]->required, $needs[0]->stock, $needs[0]->missing]);
        self::assertSame(
            [
                ['projectId' => self::PROJECT_A, 'projectName' => 'Ishtar fleet', 'remaining' => 5],
                ['projectId' => self::PROJECT_B, 'projectName' => 'Rattlesnake batch', 'remaining' => 4],
            ],
            $needs[0]->projects,
        );
    }

    public function testProjectsOfAProductAreSortedByNameThenByIdWhateverTheOrderReceived(): void
    {
        $needs = (new PlanetaryProjectNeedsBuilder())->build([
            $this->demand(self::PROJECT_C, 'Zeta run', self::COOLANT, 'Coolant', 7),
            $this->demand(self::PROJECT_B, 'Alpha run', self::COOLANT, 'Coolant', 2),
            $this->demand(self::PROJECT_A, 'Alpha run', self::COOLANT, 'Coolant', 9),
        ], [self::COOLANT => 0]);

        self::assertCount(1, $needs);
        self::assertSame(
            [self::PROJECT_A, self::PROJECT_B, self::PROJECT_C],
            array_column($needs[0]->projects, 'projectId'),
        );
        self::assertSame([9, 2, 7], array_column($needs[0]->projects, 'remaining'));
        self::assertSame(18, $needs[0]->required);
    }

    public function testProductsAreSortedByNameWithNullNamesLastThenByTypeId(): void
    {
        $needs = (new PlanetaryProjectNeedsBuilder())->build([
            $this->demand(self::PROJECT_A, 'Ishtar fleet', 7001, null, 1),
            $this->demand(self::PROJECT_A, 'Ishtar fleet', self::ROBOTICS, 'Robotics', 5),
            $this->demand(self::PROJECT_A, 'Ishtar fleet', 6001, null, 2),
            $this->demand(self::PROJECT_A, 'Ishtar fleet', self::BACTERIA, 'Bacteria', 1),
            $this->demand(self::PROJECT_A, 'Ishtar fleet', 8001, 'Coolant', 4),
            $this->demand(self::PROJECT_A, 'Ishtar fleet', self::COOLANT, 'Coolant', 3),
        ], [self::ROBOTICS => 0]);

        self::assertSame(
            [self::BACTERIA, 8001, self::COOLANT, self::ROBOTICS, 6001, 7001],
            array_map(static fn (PlanetaryProjectNeed $need): int => $need->typeId, $needs),
        );
    }

    public function testWithoutAnyDemandThereIsNoProductWhetherTheStockIsKnownOrNot(): void
    {
        $builder = new PlanetaryProjectNeedsBuilder();

        self::assertSame([], $builder->build([], [self::ROBOTICS => 50]));
        self::assertSame([], $builder->build([], []));
        self::assertSame([], $builder->build([], null));
    }

    private function demand(string $projectId, ?string $projectName, int $typeId, ?string $typeName, int $remaining): ProjectLineDemand
    {
        return new ProjectLineDemand($projectId, $projectName, $typeId, $typeName, $remaining);
    }
}
