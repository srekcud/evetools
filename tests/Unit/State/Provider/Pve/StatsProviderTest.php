<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Pve;

use ApiPlatform\Metadata\Get;
use App\Entity\User;
use App\Entity\UserLedgerSettings;
use App\Enum\PveIncomeType;
use App\Repository\PveExpenseRepository;
use App\Repository\PveIncomeRepository;
use App\Repository\UserLedgerSettingsRepository;
use App\State\Provider\Pve\StatsProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

#[CoversClass(StatsProvider::class)]
#[Group('issue-533')]
final class StatsProviderTest extends TestCase
{
    private const float EXPENSES = 400_000.0;

    public function testSoldEscalationIsCountedInItsOwnTotalAndInTheIncomeProfitAndIskPerDay(): void
    {
        $stats = $this->createProvider([
            PveIncomeType::Bounty->value => 5_000_000.0,
            PveIncomeType::Ess->value => 230_000.0,
            PveIncomeType::Mission->value => 470_000.0,
            PveIncomeType::LootSale->value => 85_000.0,
            PveIncomeType::LootContract->value => 32_000.0,
            PveIncomeType::Escalation->value => 100_000_000.0,
        ])->provide(new Get());

        $this->assertSame(100_000_000.0, $stats->totals['escalations']);
        $this->assertSame(117_000.0, $stats->totals['lootSales']);
        $this->assertSame(105_817_000.0, $stats->totals['income']);
        $this->assertSame(105_417_000.0, $stats->totals['profit']);
        $this->assertSame(3_513_900.0, $stats->iskPerDay);
    }

    public function testEscalationTotalIsZeroWithoutAnyEscalationIncome(): void
    {
        $stats = $this->createProvider([
            PveIncomeType::Bounty->value => 5_000_000.0,
            PveIncomeType::LootSale->value => 85_000.0,
        ])->provide(new Get());

        $this->assertSame(0.0, $stats->totals['escalations']);
        $this->assertSame(5_085_000.0, $stats->totals['income']);
    }

    public function testMiningCorpProjectAccountingDropsCorpProjectIncomeButKeepsEscalationIncome(): void
    {
        $stats = $this->createProvider(
            [
                PveIncomeType::Bounty->value => 5_000_000.0,
                PveIncomeType::LootSale->value => 85_000.0,
                PveIncomeType::CorpProject->value => 610_000.0,
                PveIncomeType::Escalation->value => 62_500_000.0,
            ],
            UserLedgerSettings::CORP_PROJECT_ACCOUNTING_MINING,
        )->provide(new Get());

        $this->assertSame(62_500_000.0, $stats->totals['escalations']);
        $this->assertSame(85_000.0, $stats->totals['lootSales']);
        $this->assertSame(67_585_000.0, $stats->totals['income']);
    }

    public function testPveCorpProjectAccountingCountsCorpProjectIncomeBesideEscalationIncome(): void
    {
        $stats = $this->createProvider(
            [
                PveIncomeType::Bounty->value => 5_000_000.0,
                PveIncomeType::LootSale->value => 85_000.0,
                PveIncomeType::CorpProject->value => 610_000.0,
                PveIncomeType::Escalation->value => 62_500_000.0,
            ],
            UserLedgerSettings::CORP_PROJECT_ACCOUNTING_PVE,
        )->provide(new Get());

        $this->assertSame(62_500_000.0, $stats->totals['escalations']);
        $this->assertSame(695_000.0, $stats->totals['lootSales']);
        $this->assertSame(68_195_000.0, $stats->totals['income']);
    }

    private function createProvider(array $incomeTotalsByType, string $corpProjectAccounting = UserLedgerSettings::CORP_PROJECT_ACCOUNTING_PVE): StatsProvider
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(new User());
        $incomeRepository = $this->createStub(PveIncomeRepository::class);
        $incomeRepository->method('getTotalsByType')->willReturn($incomeTotalsByType);
        $expenseRepository = $this->createStub(PveExpenseRepository::class);
        $expenseRepository->method('getTotalByUserAndDateRange')->willReturn(self::EXPENSES);
        $expenseRepository->method('getTotalsByTypeAndDateRange')->willReturn([]);
        $ledgerSettingsRepository = $this->createStub(UserLedgerSettingsRepository::class);
        $ledgerSettingsRepository->method('findByUser')->willReturn(
            (new UserLedgerSettings())->setCorpProjectAccounting($corpProjectAccounting),
        );
        $requestStack = new RequestStack();
        $requestStack->push(new Request());

        return new StatsProvider($security, $incomeRepository, $expenseRepository, $ledgerSettingsRepository, $requestStack);
    }
}
