<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;
use App\Service\Industry\Project\ProjectBreakEven;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProjectBreakEven::class)]
final class ProjectCostPerUnitTest extends TestCase
{
    private const float COST_OF_100_UNITS = 9_280_000.0;

    /**
     * @param list<array{int, int, int, bool, ?string}> $lineSpecs
     */
    private function project(array $lineSpecs, ?float $brokerFeePercent = null, ?float $salesTaxPercent = null): IndustryProject
    {
        $lines = array_map(function (array $spec): IndustryProjectBomLine {
            [$typeId, $required, $fulfilled, $isJob, $jobGroup] = $spec;
            $line = $this->createStub(IndustryProjectBomLine::class);
            $line->method('getTypeId')->willReturn($typeId);
            $line->method('getRequiredQuantity')->willReturn($required);
            $line->method('isFulfilled')->willReturn($fulfilled >= $required);
            $line->method('isJob')->willReturn($isJob);
            $line->method('getJobGroup')->willReturn($jobGroup);

            return $line;
        }, $lineSpecs);
        $project = $this->createStub(IndustryProject::class);
        $project->method('getBrokerFeePercent')->willReturn($brokerFeePercent);
        $project->method('getSalesTaxPercent')->willReturn($salesTaxPercent);
        $project->method('getBomItems')->willReturn(new ArrayCollection($lines));

        return $project;
    }

    public function testCostPerUnitIsTheApprovedCostDividedByTheUnitsOfTheFinalProductWithoutAnyFee(): void
    {
        $project = $this->project([[11993, 60, 60, true, 'final'], [11993, 40, 40, true, 'final']], 1.38, 2.62);

        $costPerUnit = (new ProjectBreakEven())->costPerUnitOf($project, self::COST_OF_100_UNITS);

        self::assertSame(92_800.0, $costPerUnit->perUnit);
        self::assertNull($costPerUnit->unknownReason);
    }

    public function testCostPerUnitIsKnownWhenTheSalesFeesAreUnknownBecauseThereIsNoSale(): void
    {
        $project = $this->project([[11993, 100, 100, true, 'final']]);

        $costPerUnit = (new ProjectBreakEven())->costPerUnitOf($project, self::COST_OF_100_UNITS);

        self::assertSame(92_800.0, $costPerUnit->perUnit);
    }

    /**
     * @param list<array{int, int, int, bool, ?string}> $lineSpecs
     */
    #[DataProvider('unknownCostsPerUnit')]
    public function testCostPerUnitIsUnknownWithItsCauseAndNeverZero(array $lineSpecs, ?float $cost, string $reason): void
    {
        $costPerUnit = (new ProjectBreakEven())->costPerUnitOf($this->project($lineSpecs), $cost);

        self::assertNull($costPerUnit->perUnit);
        self::assertSame($reason, $costPerUnit->unknownReason);
    }

    /**
     * @return iterable<string, array{list<array{int, int, int, bool, ?string}>, ?float, string}>
     */
    public static function unknownCostsPerUnit(): iterable
    {
        yield 'no final product' => [[[34, 5000, 0, false, null]], self::COST_OF_100_UNITS, 'no_final_product'];
        yield 'several final products' => [[[11993, 50, 50, true, 'final'], [11999, 50, 50, true, 'final']], self::COST_OF_100_UNITS, 'several_final_products'];
        yield 'final jobs not delivered' => [[[11993, 100, 70, true, 'final']], self::COST_OF_100_UNITS, 'jobs_not_delivered'];
        yield 'unknown cost' => [[[11993, 100, 100, true, 'final']], null, 'cost_unknown'];
        yield 'no approved cost' => [[[11993, 100, 100, true, 'final']], 0.0, 'no_cost'];
    }
}
