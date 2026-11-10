<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Pve;

use ApiPlatform\Metadata\Get;
use App\ApiResource\Pve\DailyStatsResource;
use App\Entity\User;
use App\Repository\PveExpenseRepository;
use App\Repository\PveIncomeRepository;
use App\Repository\UserLedgerSettingsRepository;
use App\State\Provider\Pve\StatsDailyProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

#[CoversClass(StatsDailyProvider::class)]
#[Group('issue-533')]
final class StatsDailyProviderTest extends TestCase
{
    public function testSoldEscalationIsCountedOnItsSaleDayInTheIncomeAndTheProfit(): void
    {
        $saleDay = (new \DateTimeImmutable('-12 days'))->format('Y-m-d');
        $otherDay = (new \DateTimeImmutable('-11 days'))->format('Y-m-d');

        $stats = $this->createProvider(
            [$saleDay => ['bounties' => 60_000.0, 'lootSales' => 40_000.0, 'corpProject' => 0.0, 'escalations' => 100_000_000.0]],
            [$saleDay => ['date' => $saleDay, 'total' => 30_000.0]],
        )->provide(new Get());

        $days = $this->daysByDate($stats->daily);
        $this->assertCount(31, $days);
        $this->assertSame(100_000_000.0, $days[$saleDay]->escalations);
        $this->assertSame(100_100_000.0, $days[$saleDay]->income);
        $this->assertSame(100_070_000.0, $days[$saleDay]->profit);
        $this->assertSame(60_000.0, $days[$saleDay]->bounties);
        $this->assertSame(40_000.0, $days[$saleDay]->lootSales);
        $this->assertSame([0.0, 0.0, 0.0], [$days[$otherDay]->escalations, $days[$otherDay]->income, $days[$otherDay]->profit]);
    }

    public function testADayWithOnlyAnEscalationSaleHasItsIncomeEqualToTheSalePrice(): void
    {
        $saleDay = (new \DateTimeImmutable('-3 days'))->format('Y-m-d');

        $stats = $this->createProvider(
            [$saleDay => ['bounties' => 0.0, 'lootSales' => 0.0, 'corpProject' => 0.0, 'escalations' => 80_000_000.0]],
            [],
        )->provide(new Get());

        $days = $this->daysByDate($stats->daily);
        $this->assertSame([80_000_000.0, 80_000_000.0, 80_000_000.0], [$days[$saleDay]->escalations, $days[$saleDay]->income, $days[$saleDay]->profit]);
    }

    private function daysByDate(array $daily): array
    {
        return array_combine(array_map(static fn (DailyStatsResource $day): string => $day->date, $daily), $daily);
    }

    private function createProvider(array $dailyIncomeByType, array $dailyExpenses): StatsDailyProvider
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(new User());
        $incomeRepository = $this->createStub(PveIncomeRepository::class);
        $incomeRepository->method('getDailyTotalsByType')->willReturn($dailyIncomeByType);
        $expenseRepository = $this->createStub(PveExpenseRepository::class);
        $expenseRepository->method('getDailyTotals')->willReturn($dailyExpenses);
        $ledgerSettingsRepository = $this->createStub(UserLedgerSettingsRepository::class);
        $ledgerSettingsRepository->method('findByUser')->willReturn(null);
        $requestStack = new RequestStack();
        $requestStack->push(new Request());

        return new StatsDailyProvider($security, $incomeRepository, $expenseRepository, $ledgerSettingsRepository, $requestStack);
    }
}
