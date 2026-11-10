<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectSale;
use App\Entity\User;
use App\Enum\ProjectStatus;
use App\Service\Industry\Project\DistributionResult;
use App\Service\Industry\Project\ProjectDistributionService;
use App\Service\Industry\Project\ProjectPriceAlert;
use App\Service\Industry\Project\ProjectPriceAlertReader;
use App\Service\Industry\Project\UnsoldProductsOfProject;
use App\Service\Industry\SellMarkets\ImmediateSale;
use App\Service\Industry\SellMarkets\SellMarketComparison;
use App\Service\Industry\SellMarkets\SellMarketComparisonResult;
use App\Service\Industry\SellMarkets\SellMarketRow;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

#[CoversClass(ProjectPriceAlertReader::class)]
#[CoversClass(ProjectPriceAlert::class)]
#[Group('issue-349')]
final class ProjectPriceAlertReaderTest extends TestCase
{
    private const int HAMMERHEAD_TYPE_ID = 2185;
    private const float BREAK_EVEN_PER_UNIT = 760_000.0;
    private const float JITA_BELOW_BREAK_EVEN = 690_000.0;

    private ProjectDistributionService&Stub $distribution;
    private SellMarketComparison&MockObject $comparison;

    protected function setUp(): void
    {
        $this->distribution = $this->createStub(ProjectDistributionService::class);
        $this->comparison = $this->createMock(SellMarketComparison::class);
    }

    public function testJitaBelowTheBreakEvenRaisesAnAlertForTheUnsoldQuantity(): void
    {
        $project = $this->projectWithSoldUnits(10, 4);
        $this->breakEvenIs(self::BREAK_EVEN_PER_UNIT);
        $this->comparison->expects(self::once())
            ->method('compareAt')
            ->with($project->getOwner(), null, self::HAMMERHEAD_TYPE_ID, 6)
            ->willReturn($this->comparisonWithJitaPrice(self::JITA_BELOW_BREAK_EVEN));

        $alert = $this->reader()->of($project);

        self::assertInstanceOf(ProjectPriceAlert::class, $alert);
        self::assertSame(
            [self::HAMMERHEAD_TYPE_ID, 'Hammerhead II', 6, self::JITA_BELOW_BREAK_EVEN, self::BREAK_EVEN_PER_UNIT, 70_000.0],
            [$alert->typeId, $alert->typeName, $alert->quantity, $alert->jitaUnitPrice, $alert->breakEvenPerUnit, $alert->gapPerUnit()],
        );
    }

    public function testJitaAtOrAboveTheBreakEvenRaisesNoAlert(): void
    {
        $this->breakEvenIs(self::BREAK_EVEN_PER_UNIT);
        $this->comparison->method('compareAt')->willReturnOnConsecutiveCalls(
            $this->comparisonWithJitaPrice(self::BREAK_EVEN_PER_UNIT),
            $this->comparisonWithJitaPrice(self::BREAK_EVEN_PER_UNIT + 1.0),
        );

        self::assertSame(
            [null, null],
            [$this->reader()->of($this->projectWithSoldUnits(10, 0)), $this->reader()->of($this->projectWithSoldUnits(10, 0))],
        );
    }

    public function testUnknownBreakEvenRaisesNoAlertAndReadsNoMarket(): void
    {
        $this->distribution->method('calculateDistribution')->willReturn($this->distributionWith(null, 'jobs_not_delivered'));
        $this->comparison->expects(self::never())->method('compareAt');

        self::assertNull($this->reader()->of($this->projectWithSoldUnits(10, 0)));
    }

    public function testPersonalUseProjectRaisesNoAlertAndReadsNoMarket(): void
    {
        $this->breakEvenIs(self::BREAK_EVEN_PER_UNIT);
        $this->comparison->expects(self::never())->method('compareAt');

        self::assertNull($this->reader()->of($this->projectWithSoldUnits(10, 0)->setPersonalUse(true)));
    }

    public function testCompletedProjectRaisesNoAlertAndComputesNothing(): void
    {
        $distribution = $this->createMock(ProjectDistributionService::class);
        $distribution->expects(self::never())->method('calculateDistribution');
        $this->comparison->expects(self::never())->method('compareAt');
        $reader = new ProjectPriceAlertReader($distribution, $this->comparison, new UnsoldProductsOfProject(), new MockClock('2026-11-11 08:00:00'));

        self::assertNull($reader->of($this->projectWithSoldUnits(10, 0)->setStatus(ProjectStatus::Completed)));
    }

    public function testUnknownJitaPriceRaisesNoAlertInsteadOfAssumingZero(): void
    {
        $this->breakEvenIs(self::BREAK_EVEN_PER_UNIT);
        $this->comparison->method('compareAt')->willReturn($this->comparisonWithJitaPrice(null));

        self::assertNull($this->reader()->of($this->projectWithSoldUnits(10, 0)));
    }

    public function testFullySoldProjectRaisesNoAlertAndReadsNoMarket(): void
    {
        $this->breakEvenIs(self::BREAK_EVEN_PER_UNIT);
        $this->comparison->expects(self::never())->method('compareAt');

        self::assertNull($this->reader()->of($this->projectWithSoldUnits(10, 10)));
    }

    private function breakEvenIs(float $perUnit): void
    {
        $this->distribution->method('calculateDistribution')->willReturn($this->distributionWith($perUnit, null));
    }

    private function distributionWith(?float $breakEvenPerUnit, ?string $unknownReason): DistributionResult
    {
        return new DistributionResult(
            totalRevenue: 0.0,
            brokerFee: null,
            salesTax: null,
            netRevenue: null,
            totalProjectCost: 7_000_000.0,
            marginPercent: null,
            members: [],
            breakEvenPerUnit: $breakEvenPerUnit,
            breakEvenUnknownReason: $unknownReason,
        );
    }

    private function comparisonWithJitaPrice(?float $unitPrice): SellMarketComparisonResult
    {
        $jita = new SellMarketRow(SellMarketComparison::JITA_KEY, SellMarketComparison::JITA_MARKET_NAME, $unitPrice, null, null, 'region_history', 0.04, null, null);

        return new SellMarketComparisonResult(self::HAMMERHEAD_TYPE_ID, 0, [$jita], new ImmediateSale(null, null, null));
    }

    private function projectWithSoldUnits(int $units, int $sold): IndustryProject
    {
        $project = (new IndustryProject())->setOwner(new User());
        $project->addBomItem(
            (new IndustryProjectBomLine())
                ->setTypeId(self::HAMMERHEAD_TYPE_ID)
                ->setTypeName('Hammerhead II')
                ->setRequiredQuantity($units)
                ->setIsJob(true)
                ->setJobGroup('final')
                ->setRuns($units),
        );
        if ($sold > 0) {
            $project->addSale(
                (new IndustryProjectSale())
                    ->setTypeId(self::HAMMERHEAD_TYPE_ID)
                    ->setTypeName('Hammerhead II')
                    ->setQuantity($sold)
                    ->setUnitPrice(800_000.0)
                    ->setTotalPrice($sold * 800_000.0)
                    ->setSoldAt(new \DateTimeImmutable('2026-11-01 10:00:00')),
            );
        }

        return $project;
    }

    private function reader(): ProjectPriceAlertReader
    {
        return new ProjectPriceAlertReader($this->distribution, $this->comparison, new UnsoldProductsOfProject(), new MockClock('2026-11-11 08:00:00'));
    }
}
