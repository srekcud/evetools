<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Ledger;

use ApiPlatform\Metadata\Get;
use App\Entity\MiningEntry;
use App\Entity\PveExpense;
use App\Entity\User;
use App\Entity\UserLedgerSettings;
use App\Enum\PveIncomeType;
use App\Repository\MiningEntryRepository;
use App\Repository\PveExpenseRepository;
use App\Repository\PveIncomeRepository;
use App\Repository\UserLedgerSettingsRepository;
use App\Repository\UserPveSettingsRepository;
use App\Service\MiningBestValueCalculator;
use App\State\Provider\Ledger\LedgerDashboardProvider;
use App\Tests\Unit\Service\Mining\BuildsOreValueServiceOnSdeOres;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

#[CoversClass(LedgerDashboardProvider::class)]
#[Group('issue-260')]
final class LedgerDashboardProviderTest extends TestCase
{
    use BuildsOreValueServiceOnSdeOres;

    private const float VALUE_DELTA = 1e-6;
    private const int TYPE_WITHOUT_ANY_PRICE = 81143;
    private const float BOUNTIES = 5_000_000.0;
    private const float EXPENSES = 400_000.0;

    public function testUnknownMiningValueMakesTheDashboardTotalsProfitAndSharesUnknownButKeepsPveAndExpenses(): void
    {
        $miningEntryRepository = $this->createStub(MiningEntryRepository::class);
        $miningEntryRepository->method('getQuantitiesByType')->willReturn([
            self::BITUMENS => ['typeId' => self::BITUMENS, 'quantity' => 1000],
            self::TYPE_WITHOUT_ANY_PRICE => ['typeId' => self::TYPE_WITHOUT_ANY_PRICE, 'quantity' => 3150],
        ]);
        $miningEntryRepository->method('getQuantitiesByTypeAndUsage')->willReturn([
            MiningEntry::USAGE_SOLD => [self::BITUMENS => 1000],
            MiningEntry::USAGE_INDUSTRY => [self::TRITANIUM => 2500, self::TYPE_WITHOUT_ANY_PRICE => 3150],
        ]);

        $dashboard = $this->createProvider($miningEntryRepository)->provide(new Get());

        $this->assertSame(self::BOUNTIES, $dashboard->totals['pve']);
        $this->assertSame(self::EXPENSES, $dashboard->totals['expenses']);
        $this->assertNull($dashboard->totals['mining']);
        $this->assertNull($dashboard->totals['total']);
        $this->assertNull($dashboard->totals['profit']);
        $this->assertNull($dashboard->iskPerDay);
        $this->assertNull($dashboard->pvePercent);
        $this->assertNull($dashboard->miningPercent);
        $this->assertSame(['sold', 'corpProject', 'industry', 'unknown'], array_keys($dashboard->miningBreakdown));
        $this->assertEqualsWithDelta(831214.8, $dashboard->miningBreakdown['sold'], self::VALUE_DELTA);
        $this->assertNull($dashboard->miningBreakdown['industry']);
        $this->assertSame(0.0, $dashboard->miningBreakdown['unknown']);
        $this->assertSame(0.0, $dashboard->miningBreakdown['corpProject']);
        $this->assertObjectHasProperty('missingPriceTypeIds', $dashboard);
        $this->assertSame([self::TYPE_WITHOUT_ANY_PRICE], $dashboard->missingPriceTypeIds);
    }

    public function testKnownMiningValueKeepsTheDashboardTotalsProfitAndShares(): void
    {
        $miningEntryRepository = $this->createStub(MiningEntryRepository::class);
        $miningEntryRepository->method('getQuantitiesByType')->willReturn([
            self::BITUMENS => ['typeId' => self::BITUMENS, 'quantity' => 1000],
            self::TRITANIUM => ['typeId' => self::TRITANIUM, 'quantity' => 2500],
        ]);
        $miningEntryRepository->method('getQuantitiesByTypeAndUsage')->willReturn([
            MiningEntry::USAGE_SOLD => [self::BITUMENS => 1000],
            MiningEntry::USAGE_INDUSTRY => [self::TRITANIUM => 2500],
        ]);

        $dashboard = $this->createProvider($miningEntryRepository)->provide(new Get());

        $this->assertSame(self::BOUNTIES, $dashboard->totals['pve']);
        $this->assertSame(self::EXPENSES, $dashboard->totals['expenses']);
        $this->assertEqualsWithDelta(841889.8, $dashboard->totals['mining'], self::VALUE_DELTA);
        $this->assertEqualsWithDelta(5841889.8, $dashboard->totals['total'], self::VALUE_DELTA);
        $this->assertEqualsWithDelta(5441889.8, $dashboard->totals['profit'], self::VALUE_DELTA);
        $this->assertEqualsWithDelta(181396.326666667, $dashboard->iskPerDay, self::VALUE_DELTA);
        $this->assertEqualsWithDelta(85.588742190926, $dashboard->pvePercent, self::VALUE_DELTA);
        $this->assertEqualsWithDelta(14.411257809074, $dashboard->miningPercent, self::VALUE_DELTA);
        $this->assertEqualsWithDelta(831214.8, $dashboard->miningBreakdown['sold'], self::VALUE_DELTA);
        $this->assertEqualsWithDelta(10675.0, $dashboard->miningBreakdown['industry'], self::VALUE_DELTA);
        $this->assertSame(0.0, $dashboard->miningBreakdown['unknown']);
        $this->assertSame(0.0, $dashboard->miningBreakdown['corpProject']);
        $this->assertObjectHasProperty('missingPriceTypeIds', $dashboard);
        $this->assertSame([], $dashboard->missingPriceTypeIds);
    }

    public function testPveModeSumsEveryPveIncomeTypeIncludingCorpProjects(): void
    {
        $miningEntryRepository = $this->createStub(MiningEntryRepository::class);
        $miningEntryRepository->method('getQuantitiesByType')->willReturn([]);
        $miningEntryRepository->method('getQuantitiesByTypeAndUsage')->willReturn([]);

        $dashboard = $this->createProvider($miningEntryRepository, $this->everyPveIncomeType())->provide(new Get());

        $this->assertSame(2_427_000.0, $dashboard->totals['pve']);
        $this->assertSame(
            ['bounties' => 1_000_000.0, 'ess' => 230_000.0, 'missions' => 470_000.0, 'lootSales' => 117_000.0, 'corpProjects' => 610_000.0],
            $dashboard->pveBreakdown,
        );
    }

    public function testRatesAreExactZerosWithoutDaysNorIncome(): void
    {
        $miningEntryRepository = $this->createStub(MiningEntryRepository::class);
        $miningEntryRepository->method('getQuantitiesByType')->willReturn([]);
        $miningEntryRepository->method('getQuantitiesByTypeAndUsage')->willReturn([]);

        $dashboard = $this->createProvider($miningEntryRepository, [], 25_000.0, UserLedgerSettings::CORP_PROJECT_ACCOUNTING_PVE, ['days' => '0'])
            ->provide(new Get());

        $this->assertSame(0.0, $dashboard->totals['total']);
        $this->assertSame(-25_000.0, $dashboard->totals['profit']);
        $this->assertSame(0.0, $dashboard->iskPerDay);
        $this->assertSame(0.0, $dashboard->pvePercent);
        $this->assertSame(0.0, $dashboard->miningPercent);
    }

    public function testPveModeExcludesCorpProjectMiningFromTheMiningTotalAndItsBreakdown(): void
    {
        $miningEntryRepository = $this->createMock(MiningEntryRepository::class);
        $miningEntryRepository->expects($this->once())
            ->method('getQuantitiesByType')
            ->with($this->isInstanceOf(User::class), $this->anything(), $this->anything(), [MiningEntry::USAGE_CORP_PROJECT])
            ->willReturn([self::BITUMENS => ['typeId' => self::BITUMENS, 'quantity' => 1000]]);
        $miningEntryRepository->method('getQuantitiesByTypeAndUsage')->willReturn([
            MiningEntry::USAGE_SOLD => [self::BITUMENS => 1000],
            MiningEntry::USAGE_CORP_PROJECT => [self::FULLERITE_C50 => 300],
        ]);

        $dashboard = $this->createProvider($miningEntryRepository, $this->everyPveIncomeType(), self::EXPENSES, UserLedgerSettings::CORP_PROJECT_ACCOUNTING_PVE)
            ->provide(new Get());

        $this->assertEqualsWithDelta(831214.8, $dashboard->totals['mining'], self::VALUE_DELTA);
        $this->assertSame(0.0, $dashboard->miningBreakdown['corpProject']);
        $this->assertSame(610_000.0, $dashboard->pveBreakdown['corpProjects']);
    }

    public function testMiningModeCountsCorpProjectMiningInTheMiningTotalAndItsBreakdown(): void
    {
        $miningEntryRepository = $this->createMock(MiningEntryRepository::class);
        $miningEntryRepository->expects($this->once())
            ->method('getQuantitiesByType')
            ->with($this->isInstanceOf(User::class), $this->anything(), $this->anything(), null)
            ->willReturn([
                self::BITUMENS => ['typeId' => self::BITUMENS, 'quantity' => 1000],
                self::FULLERITE_C50 => ['typeId' => self::FULLERITE_C50, 'quantity' => 300],
            ]);
        $miningEntryRepository->method('getQuantitiesByTypeAndUsage')->willReturn([
            MiningEntry::USAGE_SOLD => [self::BITUMENS => 1000],
            MiningEntry::USAGE_CORP_PROJECT => [self::FULLERITE_C50 => 300],
        ]);

        $dashboard = $this->createProvider($miningEntryRepository, $this->everyPveIncomeType(), self::EXPENSES, UserLedgerSettings::CORP_PROJECT_ACCOUNTING_MINING)
            ->provide(new Get());

        $this->assertSame(1_817_000.0, $dashboard->totals['pve']);
        $this->assertSame(0.0, $dashboard->pveBreakdown['corpProjects']);
        $this->assertEqualsWithDelta(1653514.8, $dashboard->totals['mining'], self::VALUE_DELTA);
        $this->assertSame(822_300.0, $dashboard->miningBreakdown['corpProject']);
    }

    public function testMiningModeLeavesTheCorpProjectMiningBreakdownUnknownWhenItHoldsATypeWithoutPrice(): void
    {
        $miningEntryRepository = $this->createMock(MiningEntryRepository::class);
        $miningEntryRepository->expects($this->once())
            ->method('getQuantitiesByType')
            ->with($this->isInstanceOf(User::class), $this->anything(), $this->anything(), null)
            ->willReturn([
                self::BITUMENS => ['typeId' => self::BITUMENS, 'quantity' => 1000],
                self::FULLERITE_C50 => ['typeId' => self::FULLERITE_C50, 'quantity' => 300],
                self::TYPE_WITHOUT_ANY_PRICE => ['typeId' => self::TYPE_WITHOUT_ANY_PRICE, 'quantity' => 700],
            ]);
        $miningEntryRepository->method('getQuantitiesByTypeAndUsage')->willReturn([
            MiningEntry::USAGE_SOLD => [self::BITUMENS => 1000],
            MiningEntry::USAGE_CORP_PROJECT => [self::TYPE_WITHOUT_ANY_PRICE => 700, self::FULLERITE_C50 => 300],
        ]);

        $dashboard = $this->createProvider($miningEntryRepository, $this->everyPveIncomeType(), self::EXPENSES, UserLedgerSettings::CORP_PROJECT_ACCOUNTING_MINING)
            ->provide(new Get());

        $this->assertEqualsWithDelta(831214.8, $dashboard->miningBreakdown['sold'], self::VALUE_DELTA);
        $this->assertNull($dashboard->miningBreakdown['corpProject']);
        $this->assertSame([self::TYPE_WITHOUT_ANY_PRICE], $dashboard->missingPriceTypeIds);
    }

    private function everyPveIncomeType(): array
    {
        return [
            PveIncomeType::Bounty->value => 1_000_000.0,
            PveIncomeType::Ess->value => 230_000.0,
            PveIncomeType::Mission->value => 470_000.0,
            PveIncomeType::LootSale->value => 85_000.0,
            PveIncomeType::LootContract->value => 32_000.0,
            PveIncomeType::CorpProject->value => 610_000.0,
        ];
    }

    private function createProvider(
        MiningEntryRepository $miningEntryRepository,
        array $pveIncomeTotalsByType = [PveIncomeType::Bounty->value => self::BOUNTIES],
        float $expensesTotal = self::EXPENSES,
        string $corpProjectAccounting = UserLedgerSettings::CORP_PROJECT_ACCOUNTING_PVE,
        array $query = [],
    ): LedgerDashboardProvider {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(new User());
        $pveIncomeRepository = $this->createStub(PveIncomeRepository::class);
        $pveIncomeRepository->method('getTotalsByType')->willReturn($pveIncomeTotalsByType);
        $pveExpenseRepository = $this->createStub(PveExpenseRepository::class);
        $pveExpenseRepository->method('getTotalByUserAndDateRange')->willReturn($expensesTotal);
        $pveExpenseRepository->method('getTotalsByTypeAndDateRange')->willReturn([PveExpense::TYPE_AMMO => $expensesTotal]);
        $ledgerSettingsRepository = $this->createStub(UserLedgerSettingsRepository::class);
        $ledgerSettingsRepository->method('findByUser')->willReturn(
            (new UserLedgerSettings())->setCorpProjectAccounting($corpProjectAccounting),
        );
        $pveSettingsRepository = $this->createStub(UserPveSettingsRepository::class);
        $pveSettingsRepository->method('findByUser')->willReturn(null);
        $requestStack = new RequestStack();
        $requestStack->push(new Request($query));

        return new LedgerDashboardProvider(
            $security,
            $pveIncomeRepository,
            $pveExpenseRepository,
            $ledgerSettingsRepository,
            $pveSettingsRepository,
            new MiningBestValueCalculator(
                $miningEntryRepository,
                $this->createOreValueService($this->jitaSellPricesWithoutCompressedOre()),
            ),
            $requestStack,
        );
    }
}
