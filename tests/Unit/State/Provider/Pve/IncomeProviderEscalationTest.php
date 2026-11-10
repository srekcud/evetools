<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Pve;

use ApiPlatform\Metadata\Get;
use App\Entity\PveIncome;
use App\Entity\User;
use App\Enum\PveIncomeType;
use App\Repository\PveExpenseRepository;
use App\Repository\PveIncomeRepository;
use App\Repository\UserPveSettingsRepository;
use App\State\Provider\Pve\IncomeProvider;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

#[CoversClass(IncomeProvider::class)]
#[AllowMockObjectsWithoutExpectations]
#[Group('issue-533')]
final class IncomeProviderEscalationTest extends TestCase
{
    public function testSoldEscalationsAreListedWithTheirTotalAndLabelledEscalation(): void
    {
        $income = $this->createProvider([
            $this->escalationIncome('Blood Raider Hideaway - Aldranette', 100_000_000.0, '2026-11-02 09:15:00'),
            $this->escalationIncome('Guristas Den - Tamo', 80_000_000.0, '2026-11-05 18:40:00'),
        ], 180_000_000.0)->provide(new Get());

        $this->assertSame(180_000_000.0, $income->escalations['total']);
        $this->assertSame(2, $income->escalations['count']);
        $this->assertSame(
            [
                ['escalation', 'Escalation', 100_000_000.0, 'Blood Raider Hideaway - Aldranette'],
                ['escalation', 'Escalation', 80_000_000.0, 'Guristas Den - Tamo'],
            ],
            array_map(
                static fn (array $entry): array => [$entry['refType'], $entry['refTypeLabel'], $entry['amount'], $entry['description']],
                $income->escalations['entries'],
            ),
        );
    }

    public function testEscalationsAreCountedInTheProfitBesideBountiesLootSalesAndExpenses(): void
    {
        $income = $this->createProvider([], 100_000_000.0)->provide(new Get());

        $this->assertSame(18_400_000.0, $income->bounties['total']);
        $this->assertSame(6_250_000.0, $income->lootSales['total']);
        $this->assertSame(122_475_000.0, $income->profit);
    }

    public function testWithoutAnySoldEscalationTheBlockIsEmptyAndTheProfitIsUnchanged(): void
    {
        $income = $this->createProvider([], 0.0)->provide(new Get());

        $this->assertSame(['total' => 0.0, 'count' => 0, 'entries' => []], $income->escalations);
        $this->assertSame(22_475_000.0, $income->profit);
    }

    private function escalationIncome(string $description, float $amount, string $soldAt): PveIncome
    {
        return (new PveIncome())
            ->setType(PveIncomeType::Escalation)
            ->setDescription($description)
            ->setAmount($amount)
            ->setDate(new \DateTimeImmutable($soldAt));
    }

    private function createProvider(array $escalationIncomes, float $escalationsTotal): IncomeProvider
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(new User());

        $incomeRepository = $this->createStub(PveIncomeRepository::class);
        $incomeRepository->method('findBountiesByUserAndDateRange')->willReturn([]);
        $incomeRepository->method('getTotalBountiesByUserAndDateRange')->willReturn(18_400_000.0);
        $incomeRepository->method('findLootSalesByUserAndDateRange')->willReturn([]);
        $incomeRepository->method('getTotalLootSalesByUserAndDateRange')->willReturn(6_250_000.0);
        $incomeRepository->method('findEscalationsByUserAndDateRange')->willReturn($escalationIncomes);
        $incomeRepository->method('getTotalEscalationsByUserAndDateRange')->willReturn($escalationsTotal);

        $expenseRepository = $this->createStub(PveExpenseRepository::class);
        $expenseRepository->method('getTotalByUserAndDateRange')->willReturn(2_175_000.0);
        $expenseRepository->method('getTotalsByTypeAndDateRange')->willReturn([]);

        $settingsRepository = $this->createStub(UserPveSettingsRepository::class);
        $settingsRepository->method('findByUser')->willReturn(null);

        $requestStack = new RequestStack();
        $requestStack->push(new Request());

        return new IncomeProvider($security, $incomeRepository, $expenseRepository, $settingsRepository, $requestStack);
    }
}
