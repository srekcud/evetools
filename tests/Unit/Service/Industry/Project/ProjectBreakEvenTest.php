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
final class ProjectBreakEvenTest extends TestCase
{
    private const float COST_OF_100_UNITS = 9_280_000.0;

    /**
     * @param list<IndustryProjectBomLine> $lines
     */
    private function project(array $lines, ?float $brokerFeePercent = 1.38, ?float $salesTaxPercent = 2.62): IndustryProject
    {
        $project = $this->createStub(IndustryProject::class);
        $project->method('getBrokerFeePercent')->willReturn($brokerFeePercent);
        $project->method('getSalesTaxPercent')->willReturn($salesTaxPercent);
        $project->method('getBomItems')->willReturn(new ArrayCollection($lines));

        return $project;
    }

    private function line(int $typeId, int $required, int $fulfilled, bool $isJob = true, ?string $jobGroup = 'final'): IndustryProjectBomLine
    {
        $line = $this->createStub(IndustryProjectBomLine::class);
        $line->method('getTypeId')->willReturn($typeId);
        $line->method('getRequiredQuantity')->willReturn($required);
        $line->method('isFulfilled')->willReturn($fulfilled >= $required);
        $line->method('isJob')->willReturn($isJob);
        $line->method('getJobGroup')->willReturn($jobGroup);

        return $line;
    }

    public function testBreakEvenIsTheCostPerUnitDividedByWhatRemainsAfterTheSalesFees(): void
    {
        $project = $this->project([$this->line(11993, 100, 100)]);

        $breakEven = (new ProjectBreakEven())->of($project, self::COST_OF_100_UNITS);

        self::assertEqualsWithDelta(96_666.67, $breakEven->perUnit, 0.01);
        self::assertNull($breakEven->unknownReason);
    }

    public function testBreakEvenCountsTheUnitsOfEveryFinalLineOfTheSameProduct(): void
    {
        $project = $this->project([$this->line(11993, 60, 60), $this->line(11993, 40, 40)]);

        $breakEven = (new ProjectBreakEven())->of($project, self::COST_OF_100_UNITS);

        self::assertEqualsWithDelta(96_666.67, $breakEven->perUnit, 0.01);
    }

    public function testBreakEvenIgnoresTheLinesThatAreNotFinalJobs(): void
    {
        $project = $this->project([
            $this->line(11993, 100, 100),
            $this->line(34, 5000, 0, false, null),
            $this->line(11399, 10, 4, true, 't1'),
        ]);

        $breakEven = (new ProjectBreakEven())->of($project, self::COST_OF_100_UNITS);

        self::assertEqualsWithDelta(96_666.67, $breakEven->perUnit, 0.01);
    }

    /**
     * @param list<array{int, int, int, bool, ?string}> $lineSpecs
     */
    #[DataProvider('unknownBreakEvens')]
    public function testBreakEvenIsUnknownWithItsCauseAndNeverZero(array $lineSpecs, ?float $brokerFee, ?float $salesTax, ?float $cost, string $reason): void
    {
        $lines = array_map(fn (array $spec): IndustryProjectBomLine => $this->line(...$spec), $lineSpecs);

        $breakEven = (new ProjectBreakEven())->of($this->project($lines, $brokerFee, $salesTax), $cost);

        self::assertNull($breakEven->perUnit);
        self::assertSame($reason, $breakEven->unknownReason);
    }

    /**
     * @return iterable<string, array{list<array{int, int, int, bool, ?string}>, ?float, ?float, ?float, string}>
     */
    public static function unknownBreakEvens(): iterable
    {
        yield 'no final product' => [[[34, 5000, 0, false, null]], 1.38, 2.62, self::COST_OF_100_UNITS, 'no_final_product'];
        yield 'several final products' => [[[11993, 50, 50, true, 'final'], [11999, 50, 50, true, 'final']], 1.38, 2.62, self::COST_OF_100_UNITS, 'several_final_products'];
        yield 'final jobs not delivered' => [[[11993, 100, 70, true, 'final']], 1.38, 2.62, self::COST_OF_100_UNITS, 'jobs_not_delivered'];
        yield 'unknown cost' => [[[11993, 100, 100, true, 'final']], 1.38, 2.62, null, 'cost_unknown'];
        yield 'no approved cost' => [[[11993, 100, 100, true, 'final']], 1.38, 2.62, 0.0, 'no_cost'];
        yield 'unknown broker fee' => [[[11993, 100, 100, true, 'final']], null, 2.62, self::COST_OF_100_UNITS, 'fees_unknown'];
        yield 'unknown sales tax' => [[[11993, 100, 100, true, 'final']], 1.38, null, self::COST_OF_100_UNITS, 'fees_unknown'];
    }
}
