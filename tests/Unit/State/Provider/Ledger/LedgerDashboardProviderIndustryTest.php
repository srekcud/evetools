<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Ledger;

use ApiPlatform\Metadata\Get;
use App\Entity\User;
use App\Entity\UserLedgerSettings;
use App\Enum\PveIncomeType;
use App\Repository\MiningEntryRepository;
use App\Repository\PveExpenseRepository;
use App\Repository\PveIncomeRepository;
use App\Repository\UserLedgerSettingsRepository;
use App\Repository\UserPveSettingsRepository;
use App\Service\Industry\Revenue\IndustryRevenueReader;
use App\Service\Industry\Revenue\IndustryRevenueReport;
use App\Service\Industry\Revenue\IndustryRevenueTotals;
use App\Service\MiningBestValueCalculator;
use App\State\Provider\Ledger\LedgerDashboardProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

#[CoversClass(LedgerDashboardProvider::class)]
#[Group('issue-360')]
final class LedgerDashboardProviderIndustryTest extends TestCase
{
    private const float PVE_BOUNTIES = 5_000_000.0;
    private const float MINING_VALUE = 1_000_000.0;
    private const float INDUSTRY_PROFIT = 2_000_000.0;
    private const float EXPENSES = 500_000.0;
    private const float VALUE_DELTA = 1e-6;

    public function testIndustryProfitJoinsTheDashboardTotalProfitAndShares(): void
    {
        $dashboard = $this->createProvider(self::MINING_VALUE, $this->industryReport(self::INDUSTRY_PROFIT, 3, 1))->provide(new Get());

        $this->assertSame(self::INDUSTRY_PROFIT, $dashboard->totals['industry']);
        $this->assertSame(8_000_000.0, $dashboard->totals['total']);
        $this->assertSame(7_500_000.0, $dashboard->totals['profit']);
        $this->assertSame(250_000.0, $dashboard->iskPerDay);
        $this->assertSame(62.5, $dashboard->pvePercent);
        $this->assertSame(12.5, $dashboard->miningPercent);
        $this->assertSame(25.0, $dashboard->industryPercent);
        $this->assertSame(
            ['profit' => self::INDUSTRY_PROFIT, 'projectCount' => 3, 'unknownProjectCount' => 1],
            $dashboard->industryBreakdown,
        );
    }

    public function testWithoutCompletedProjectTheIndustryTotalIsZeroAndTheOtherFiguresAreUnchanged(): void
    {
        $dashboard = $this->createProvider(self::MINING_VALUE, $this->industryReport(0.0, 0, 0))->provide(new Get());

        $this->assertSame(0.0, $dashboard->totals['industry']);
        $this->assertSame(6_000_000.0, $dashboard->totals['total']);
        $this->assertSame(5_500_000.0, $dashboard->totals['profit']);
        $this->assertEqualsWithDelta(183_333.333333, $dashboard->iskPerDay, self::VALUE_DELTA);
        $this->assertEqualsWithDelta(83.333333, $dashboard->pvePercent, self::VALUE_DELTA);
        $this->assertEqualsWithDelta(16.666667, $dashboard->miningPercent, self::VALUE_DELTA);
        $this->assertSame(0.0, $dashboard->industryPercent);
        $this->assertSame(
            ['profit' => 0.0, 'projectCount' => 0, 'unknownProjectCount' => 0],
            $dashboard->industryBreakdown,
        );
    }

    public function testUnknownMiningValueKeepsTheIndustryTotalButLeavesItsShareUnknown(): void
    {
        $dashboard = $this->createProvider(null, $this->industryReport(self::INDUSTRY_PROFIT, 2, 0))->provide(new Get());

        $this->assertSame(self::INDUSTRY_PROFIT, $dashboard->totals['industry']);
        $this->assertNull($dashboard->totals['total']);
        $this->assertNull($dashboard->totals['profit']);
        $this->assertNull($dashboard->industryPercent);
    }

    public function testNegativeCombinedTotalGivesZeroShares(): void
    {
        $dashboard = $this->createProvider(0.0, $this->industryReport(-3_000_000.0, 1, 0), [])->provide(new Get());

        $this->assertSame(-3_000_000.0, $dashboard->totals['industry']);
        $this->assertSame(-3_000_000.0, $dashboard->totals['total']);
        $this->assertSame(-3_500_000.0, $dashboard->totals['profit']);
        $this->assertSame(0.0, $dashboard->industryPercent);
        $this->assertSame(0.0, $dashboard->pvePercent);
        $this->assertSame(0.0, $dashboard->miningPercent);
    }

    public function testIndustryRevenueIsReadOverTheRequestedPeriodForTheConnectedUser(): void
    {
        $user = new User();
        $reader = $this->createMock(IndustryRevenueReader::class);
        $reader->expects($this->once())
            ->method('reportFor')
            ->with(
                $this->identicalTo($user),
                $this->callback(static fn (\DateTimeImmutable $from): bool => $from->format('Y-m-d') === (new \DateTimeImmutable('-7 days'))->format('Y-m-d')),
                $this->callback(static fn (\DateTimeImmutable $to): bool => $to->format('Y-m-d') === (new \DateTimeImmutable())->format('Y-m-d')),
            )
            ->willReturn($this->industryReport(0.0, 0, 0));

        $this->buildProvider($user, self::MINING_VALUE, $reader, [PveIncomeType::Bounty->value => self::PVE_BOUNTIES], ['days' => '7'])
            ->provide(new Get());
    }

    private function industryReport(float $profit, int $projectCount, int $unknownProjectCount): IndustryRevenueReport
    {
        return new IndustryRevenueReport(
            new IndustryRevenueTotals($profit, $projectCount, $unknownProjectCount, 0.0, null, null),
            [],
            [],
        );
    }

    private function createProvider(
        ?float $miningValue,
        IndustryRevenueReport $report,
        array $pveIncomeTotalsByType = [PveIncomeType::Bounty->value => self::PVE_BOUNTIES],
    ): LedgerDashboardProvider {
        $reader = $this->createStub(IndustryRevenueReader::class);
        $reader->method('reportFor')->willReturn($report);

        return $this->buildProvider(new User(), $miningValue, $reader, $pveIncomeTotalsByType, []);
    }

    private function buildProvider(
        User $user,
        ?float $miningValue,
        IndustryRevenueReader $reader,
        array $pveIncomeTotalsByType,
        array $query,
    ): LedgerDashboardProvider {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);
        $pveIncomeRepository = $this->createStub(PveIncomeRepository::class);
        $pveIncomeRepository->method('getTotalsByType')->willReturn($pveIncomeTotalsByType);
        $pveIncomeRepository->method('findOldestDate')->willReturn(null);
        $pveExpenseRepository = $this->createStub(PveExpenseRepository::class);
        $pveExpenseRepository->method('getTotalByUserAndDateRange')->willReturn(self::EXPENSES);
        $pveExpenseRepository->method('getTotalsByTypeAndDateRange')->willReturn([]);
        $pveExpenseRepository->method('findOldestDate')->willReturn(null);
        $ledgerSettingsRepository = $this->createStub(UserLedgerSettingsRepository::class);
        $ledgerSettingsRepository->method('findByUser')->willReturn(
            (new UserLedgerSettings())->setCorpProjectAccounting(UserLedgerSettings::CORP_PROJECT_ACCOUNTING_PVE),
        );
        $pveSettingsRepository = $this->createStub(UserPveSettingsRepository::class);
        $pveSettingsRepository->method('findByUser')->willReturn(null);
        $miningEntryRepository = $this->createStub(MiningEntryRepository::class);
        $miningEntryRepository->method('findOldestDate')->willReturn(null);
        $miningBestValueCalculator = $this->createStub(MiningBestValueCalculator::class);
        $miningBestValueCalculator->method('getTotalBestValue')->willReturn(['totalValue' => $miningValue, 'missingPriceTypeIds' => []]);
        $miningBestValueCalculator->method('getTotalsByUsageBestValue')->willReturn([]);
        $requestStack = new RequestStack();
        $requestStack->push(new Request($query));

        return new LedgerDashboardProvider(
            $security,
            $pveIncomeRepository,
            $pveExpenseRepository,
            $ledgerSettingsRepository,
            $pveSettingsRepository,
            $miningBestValueCalculator,
            $requestStack,
            $miningEntryRepository,
            $reader,
        );
    }
}
