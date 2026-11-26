<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectSale;
use App\Service\Industry\Project\UnmatchedSale;
use App\Service\Industry\Project\UnmatchedSalesOfProject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(UnmatchedSalesOfProject::class)]
#[Group('issue-1376')]
final class UnmatchedSalesOfProjectTest extends TestCase
{
    private const int HAMMERHEAD_TYPE_ID = 2185;
    private const int RAVEN_TYPE_ID = 638;
    private const int SABRE_TYPE_ID = 22456;

    public function testSaleOfATypeOutsideTheFinalProductsIsListedWithItsQuantityAndRevenue(): void
    {
        $project = $this->projectOf($this->finalJobLine(self::HAMMERHEAD_TYPE_ID, 'Hammerhead II', 100));

        $unmatchedSales = (new UnmatchedSalesOfProject())->of($project, [
            $this->sale(self::RAVEN_TYPE_ID, 'Raven', 3, 11_100_000.0),
        ]);

        self::assertEquals([new UnmatchedSale(self::RAVEN_TYPE_ID, 'Raven', 3, 33_300_000.0)], $unmatchedSales);
    }

    public function testSalesOfTheSameUnmatchedTypeAddUp(): void
    {
        $project = $this->projectOf($this->finalJobLine(self::HAMMERHEAD_TYPE_ID, 'Hammerhead II', 100));

        $unmatchedSales = (new UnmatchedSalesOfProject())->of($project, [
            $this->sale(self::RAVEN_TYPE_ID, 'Raven', 1, 10_000_000.0),
            $this->sale(self::RAVEN_TYPE_ID, 'Raven', 2, 11_000_000.0),
        ]);

        self::assertEquals([new UnmatchedSale(self::RAVEN_TYPE_ID, 'Raven', 3, 32_000_000.0)], $unmatchedSales);
    }

    public function testSaleOfAFinalProductIsNotUnmatched(): void
    {
        $project = $this->projectOf($this->finalJobLine(self::HAMMERHEAD_TYPE_ID, 'Hammerhead II', 100));

        $unmatchedSales = (new UnmatchedSalesOfProject())->of($project, [
            $this->sale(self::HAMMERHEAD_TYPE_ID, 'Hammerhead II', 40, 800_000.0),
        ]);

        self::assertSame([], $unmatchedSales);
    }

    public function testSaleOfAnIntermediateComponentIsUnmatchedBecauseOnlyFinalJobsAreProductsToSell(): void
    {
        $project = $this->projectOf(
            $this->finalJobLine(self::HAMMERHEAD_TYPE_ID, 'Hammerhead II', 100),
            $this->jobLine(self::SABRE_TYPE_ID, 'Sabre', 'component', 100),
        );

        $unmatchedSales = (new UnmatchedSalesOfProject())->of($project, [
            $this->sale(self::SABRE_TYPE_ID, 'Sabre', 10, 1_000_000.0),
        ]);

        self::assertEquals([new UnmatchedSale(self::SABRE_TYPE_ID, 'Sabre', 10, 10_000_000.0)], $unmatchedSales);
    }

    public function testMatchedAndUnmatchedSalesAreSeparatedAndUnmatchedTypesAreOrderedByName(): void
    {
        $project = $this->projectOf($this->finalJobLine(self::HAMMERHEAD_TYPE_ID, 'Hammerhead II', 100));

        $unmatchedSales = (new UnmatchedSalesOfProject())->of($project, [
            $this->sale(self::SABRE_TYPE_ID, 'Sabre', 1, 1_300_000.0),
            $this->sale(self::HAMMERHEAD_TYPE_ID, 'Hammerhead II', 4, 800_000.0),
            $this->sale(self::RAVEN_TYPE_ID, 'Raven', 1, 190_000_000.0),
        ]);

        self::assertEquals([
            new UnmatchedSale(self::RAVEN_TYPE_ID, 'Raven', 1, 190_000_000.0),
            new UnmatchedSale(self::SABRE_TYPE_ID, 'Sabre', 1, 1_300_000.0),
        ], $unmatchedSales);
    }

    public function testEverySaleIsUnmatchedWhenTheProjectHasNoFinalJob(): void
    {
        $project = $this->projectOf();

        $unmatchedSales = (new UnmatchedSalesOfProject())->of($project, [
            $this->sale(self::RAVEN_TYPE_ID, 'Raven', 1, 190_000_000.0),
        ]);

        self::assertEquals([new UnmatchedSale(self::RAVEN_TYPE_ID, 'Raven', 1, 190_000_000.0)], $unmatchedSales);
    }

    public function testNoSaleMeansNothingUnmatched(): void
    {
        $project = $this->projectOf($this->finalJobLine(self::HAMMERHEAD_TYPE_ID, 'Hammerhead II', 100));

        self::assertSame([], (new UnmatchedSalesOfProject())->of($project, []));
    }

    private function projectOf(IndustryProjectBomLine ...$lines): IndustryProject
    {
        $project = new IndustryProject();
        foreach ($lines as $line) {
            $project->addBomItem($line);
        }

        return $project;
    }

    private function finalJobLine(int $typeId, string $typeName, int $requiredQuantity): IndustryProjectBomLine
    {
        return $this->jobLine($typeId, $typeName, 'final', $requiredQuantity);
    }

    private function jobLine(int $typeId, string $typeName, string $jobGroup, int $requiredQuantity): IndustryProjectBomLine
    {
        return (new IndustryProjectBomLine())
            ->setTypeId($typeId)
            ->setTypeName($typeName)
            ->setRequiredQuantity($requiredQuantity)
            ->setIsJob(true)
            ->setJobGroup($jobGroup);
    }

    private function sale(int $typeId, string $typeName, int $quantity, float $unitPrice): IndustryProjectSale
    {
        return (new IndustryProjectSale())
            ->setTypeId($typeId)
            ->setTypeName($typeName)
            ->setQuantity($quantity)
            ->setUnitPrice($unitPrice)
            ->setTotalPrice($quantity * $unitPrice)
            ->setSoldAt(new \DateTimeImmutable('2026-11-01 10:00:00'));
    }
}
