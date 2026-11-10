<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;
use App\Service\Industry\Project\RemainingBomCost;
use App\Service\Industry\Project\RemainingCost;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(RemainingBomCost::class)]
#[CoversClass(RemainingCost::class)]
#[Group('issue-483')]
final class RemainingBomCostTest extends TestCase
{
    private const int TRITANIUM_TYPE_ID = 34;
    private const int PYERITE_TYPE_ID = 35;
    private const int MEXALLON_TYPE_ID = 36;
    private const int HAMMERHEAD_TYPE_ID = 2185;
    private const int SABRE_TYPE_ID = 22456;

    public function testRemainingCostIsTheRemainingQuantityTimesTheEstimatedPriceOfEachMaterialLine(): void
    {
        $project = $this->projectOf(
            $this->materialLine(self::TRITANIUM_TYPE_ID, 10, 4, 100.0),
            $this->materialLine(self::PYERITE_TYPE_ID, 3, 0, 1_000.0),
        );

        $cost = (new RemainingBomCost())->of($project);

        self::assertSame(3_600.0, $cost->total);
        self::assertSame(0, $cost->unknownLineCount);
    }

    public function testRemainingCostIsZeroWhenEveryMaterialLineIsFulfilled(): void
    {
        $project = $this->projectOf(
            $this->materialLine(self::TRITANIUM_TYPE_ID, 10, 10, 100.0),
            $this->materialLine(self::PYERITE_TYPE_ID, 3, 3, 1_000.0),
        );

        $cost = (new RemainingBomCost())->of($project);

        self::assertSame(0.0, $cost->total);
        self::assertSame(0, $cost->unknownLineCount);
    }

    public function testRemainingCostIsZeroWhenTheProjectHasNoMaterialLine(): void
    {
        $cost = (new RemainingBomCost())->of($this->projectOf());

        self::assertSame(0.0, $cost->total);
        self::assertSame(0, $cost->unknownLineCount);
    }

    public function testRemainingCostIsUnknownWhenARemainingMaterialLineHasNoPriceAndNeverAPartialSum(): void
    {
        $project = $this->projectOf(
            $this->materialLine(self::TRITANIUM_TYPE_ID, 10, 4, 100.0),
            $this->materialLine(self::PYERITE_TYPE_ID, 7, 2, null),
        );

        $cost = (new RemainingBomCost())->of($project);

        self::assertNull($cost->total);
        self::assertSame(1, $cost->unknownLineCount);
    }

    public function testRemainingCostCountsEveryMaterialLineWithoutPrice(): void
    {
        $project = $this->projectOf(
            $this->materialLine(self::TRITANIUM_TYPE_ID, 10, 4, null),
            $this->materialLine(self::PYERITE_TYPE_ID, 7, 2, null),
            $this->materialLine(self::MEXALLON_TYPE_ID, 5, 0, 20.0),
        );

        $cost = (new RemainingBomCost())->of($project);

        self::assertNull($cost->total);
        self::assertSame(2, $cost->unknownLineCount);
    }

    public function testFullyFulfilledMaterialLineWithoutPriceIsIgnored(): void
    {
        $project = $this->projectOf(
            $this->materialLine(self::TRITANIUM_TYPE_ID, 10, 10, null),
            $this->materialLine(self::PYERITE_TYPE_ID, 3, 0, 1_000.0),
        );

        $cost = (new RemainingBomCost())->of($project);

        self::assertSame(3_000.0, $cost->total);
        self::assertSame(0, $cost->unknownLineCount);
    }

    public function testOverfulfilledMaterialLineContributesZero(): void
    {
        $project = $this->projectOf(
            $this->materialLine(self::TRITANIUM_TYPE_ID, 10, 25, 100.0),
            $this->materialLine(self::PYERITE_TYPE_ID, 3, 0, 1_000.0),
        );

        $cost = (new RemainingBomCost())->of($project);

        self::assertSame(3_000.0, $cost->total);
    }

    public function testJobLinesAreNeverCountedWhateverTheirPrice(): void
    {
        $project = $this->projectOf(
            $this->materialLine(self::TRITANIUM_TYPE_ID, 10, 4, 100.0),
            $this->jobLine(self::HAMMERHEAD_TYPE_ID, 6, 0, 750_000.0),
            $this->jobLine(self::SABRE_TYPE_ID, 3, 0, null),
        );

        $cost = (new RemainingBomCost())->of($project);

        self::assertSame(600.0, $cost->total);
        self::assertSame(0, $cost->unknownLineCount);
    }

    private function projectOf(IndustryProjectBomLine ...$lines): IndustryProject
    {
        $project = new IndustryProject();
        foreach ($lines as $line) {
            $project->addBomItem($line);
        }

        return $project;
    }

    private function materialLine(int $typeId, int $requiredQuantity, int $fulfilledQuantity, ?float $estimatedPrice): IndustryProjectBomLine
    {
        return (new IndustryProjectBomLine())
            ->setTypeId($typeId)
            ->setTypeName('Material ' . $typeId)
            ->setRequiredQuantity($requiredQuantity)
            ->setFulfilledQuantity($fulfilledQuantity)
            ->setEstimatedPrice($estimatedPrice)
            ->setIsJob(false);
    }

    private function jobLine(int $typeId, int $requiredQuantity, int $fulfilledQuantity, ?float $estimatedPrice): IndustryProjectBomLine
    {
        return (new IndustryProjectBomLine())
            ->setTypeId($typeId)
            ->setTypeName('Job ' . $typeId)
            ->setRequiredQuantity($requiredQuantity)
            ->setFulfilledQuantity($fulfilledQuantity)
            ->setEstimatedPrice($estimatedPrice)
            ->setIsJob(true)
            ->setJobGroup('intermediate')
            ->setRuns($requiredQuantity);
    }
}
