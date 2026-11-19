<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Pve;

use ApiPlatform\Metadata\Get;
use App\Entity\User;
use App\Entity\UserLedgerSettings;
use App\Repository\PveExpenseRepository;
use App\Repository\PveIncomeRepository;
use App\Repository\UserLedgerSettingsRepository;
use App\Service\Pve\PveRevenueGridBuilder;
use App\State\Provider\Pve\StatsGridProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

#[CoversClass(StatsGridProvider::class)]
#[Group('issue-1065')]
#[Group('issue-1079')]
final class StatsGridProviderTest extends TestCase
{
    public function testItExposesThePeriodTheColumnsAndTheNet(): void
    {
        $grid = $this->createProvider(new User(), new Request(['days' => '14']))->provide(new Get());

        $this->assertSame(14, $grid->period['days']);
        $this->assertSame(['bounties', 'ess', 'missions', 'lootSales', 'escalations', 'expenses'], array_keys($grid->columns));
        $this->assertSame(0.0, $grid->net['value']);
        $this->assertNull($grid->net['change']);
    }

    public function testItDefaultsToThirtyDays(): void
    {
        $this->assertSame(30, $this->createProvider(new User(), new Request())->provide(new Get())->period['days']);
    }

    public function testItRefusesAPeriodOutsideTheAllowedRange(): void
    {
        $this->expectException(BadRequestHttpException::class);

        $this->createProvider(new User(), new Request(['days' => '0']))->provide(new Get());
    }

    public function testItRefusesAnAnonymousVisitor(): void
    {
        $this->expectException(UnauthorizedHttpException::class);

        $this->createProvider(null, new Request())->provide(new Get());
    }

    #[DataProvider('scanStates')]
    public function testItExposesWhenEachScanLastRanAndWhetherItIsOverdue(?string $lootScanAge, ?string $expenseScanAge, array $expected): void
    {
        $settings = new UserLedgerSettings();
        $settings->setLastLootScanAt($lootScanAge === null ? null : new \DateTimeImmutable($lootScanAge));
        $settings->setLastExpenseScanAt($expenseScanAge === null ? null : new \DateTimeImmutable($expenseScanAge));

        $grid = $this->createProvider(new User(), new Request(), $settings)->provide(new Get());

        $this->assertSame($expected['lootSales'], $this->scanOf($grid->scans, 'lootSales', $settings->getLastLootScanAt()));
        $this->assertSame($expected['expenses'], $this->scanOf($grid->scans, 'expenses', $settings->getLastExpenseScanAt()));
    }

    /**
     * @return iterable<string, array{string|null, string|null, array{lootSales: bool, expenses: bool}}>
     */
    public static function scanStates(): iterable
    {
        yield 'both scanned yesterday' => ['-1 day', '-1 day', ['lootSales' => false, 'expenses' => false]];
        yield 'loot scanned eight days ago' => ['-8 days', '-1 day', ['lootSales' => true, 'expenses' => false]];
        yield 'expenses scanned eight days ago' => ['-1 day', '-8 days', ['lootSales' => false, 'expenses' => true]];
        yield 'loot never scanned' => [null, '-1 day', ['lootSales' => true, 'expenses' => false]];
        yield 'nothing ever scanned' => [null, null, ['lootSales' => true, 'expenses' => true]];
    }

    public function testWithoutLedgerSettingsBothScansHaveNeverRunAndAreOverdue(): void
    {
        $grid = $this->createProvider(new User(), new Request(), null)->provide(new Get());

        $this->assertSame(['lastScanAt' => null, 'overdue' => true], $grid->scans['lootSales']);
        $this->assertSame(['lastScanAt' => null, 'overdue' => true], $grid->scans['expenses']);
    }

    /**
     * @param array<string, array{lastScanAt: string|null, overdue: bool}> $scans
     */
    private function scanOf(array $scans, string $column, ?\DateTimeImmutable $lastScanAt): bool
    {
        $this->assertSame($lastScanAt?->format(\DATE_ATOM), $scans[$column]['lastScanAt']);

        return $scans[$column]['overdue'];
    }

    private function createProvider(?User $user, Request $request, ?UserLedgerSettings $ledgerSettings = new UserLedgerSettings()): StatsGridProvider
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);
        $incomeRepository = $this->createStub(PveIncomeRepository::class);
        $incomeRepository->method('getTotalsByType')->willReturn([]);
        $incomeRepository->method('getDailyTotalsPerIncomeType')->willReturn([]);
        $incomeRepository->method('getTotalsByTypeAndDescription')->willReturn([]);
        $expenseRepository = $this->createStub(PveExpenseRepository::class);
        $expenseRepository->method('getTotalByUserAndDateRange')->willReturn(0.0);
        $expenseRepository->method('getDailyTotals')->willReturn([]);
        $ledgerSettingsRepository = $this->createStub(UserLedgerSettingsRepository::class);
        $ledgerSettingsRepository->method('findByUser')->willReturn($ledgerSettings);
        $requestStack = new RequestStack();
        $requestStack->push($request);

        return new StatsGridProvider($security, new PveRevenueGridBuilder($incomeRepository, $expenseRepository), $ledgerSettingsRepository, $requestStack);
    }
}
