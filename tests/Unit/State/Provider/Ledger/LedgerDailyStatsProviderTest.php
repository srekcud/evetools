<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Ledger;

use ApiPlatform\Metadata\Get;
use App\ApiResource\Ledger\LedgerDayResource;
use App\Entity\User;
use App\Repository\MiningEntryRepository;
use App\Repository\PveExpenseRepository;
use App\Repository\PveIncomeRepository;
use App\Repository\UserLedgerSettingsRepository;
use App\Service\MiningBestValueCalculator;
use App\State\Provider\Ledger\LedgerDailyStatsProvider;
use App\Tests\Unit\Service\Mining\BuildsOreValueServiceOnSdeOres;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

#[CoversClass(LedgerDailyStatsProvider::class)]
#[Group('issue-260')]
final class LedgerDailyStatsProviderTest extends TestCase
{
    use BuildsOreValueServiceOnSdeOres;

    private const float VALUE_DELTA = 1e-6;
    private const int TYPE_WITHOUT_ANY_PRICE = 81143;

    public function testOnlyTheDayWithUnknownMiningHasUnknownTotalAndProfitWhilePveAndExpensesStayKnown(): void
    {
        $knownDay = (new \DateTimeImmutable('-20 days'))->format('Y-m-d');
        $unknownDay = (new \DateTimeImmutable('-19 days'))->format('Y-m-d');
        $pveIncomeRepository = $this->createStub(PveIncomeRepository::class);
        $pveIncomeRepository->method('getDailyTotalsByType')->willReturn([
            $unknownDay => ['bounties' => 95_000.0, 'lootSales' => 25_000.0, 'corpProject' => 0.0],
        ]);
        $pveExpenseRepository = $this->createStub(PveExpenseRepository::class);
        $pveExpenseRepository->method('getDailyTotals')->willReturn([
            $unknownDay => ['date' => $unknownDay, 'total' => 15_000.0],
        ]);
        $miningEntryRepository = $this->createStub(MiningEntryRepository::class);
        $miningEntryRepository->method('getQuantitiesByTypeAndDate')->willReturn([
            $knownDay => [self::BITUMENS => 1000],
            $unknownDay => [self::TRITANIUM => 2500, self::TYPE_WITHOUT_ANY_PRICE => 3150],
        ]);

        $dailyStats = $this->createProvider($pveIncomeRepository, $pveExpenseRepository, $miningEntryRepository)->provide(new Get());

        $daysByDate = array_combine(
            array_map(static fn (LedgerDayResource $day): string => $day->date, $dailyStats->daily),
            $dailyStats->daily,
        );
        $this->assertCount(31, $daysByDate);
        $this->assertSame(120_000.0, $daysByDate[$unknownDay]->pve);
        $this->assertSame(15_000.0, $daysByDate[$unknownDay]->expenses);
        $this->assertNull($daysByDate[$unknownDay]->mining);
        $this->assertNull($daysByDate[$unknownDay]->total);
        $this->assertNull($daysByDate[$unknownDay]->profit);
        $this->assertSame(0.0, $daysByDate[$knownDay]->pve);
        $this->assertEqualsWithDelta(831214.8, $daysByDate[$knownDay]->mining, self::VALUE_DELTA);
        $this->assertEqualsWithDelta(831214.8, $daysByDate[$knownDay]->total, self::VALUE_DELTA);
        $this->assertEqualsWithDelta(831214.8, $daysByDate[$knownDay]->profit, self::VALUE_DELTA);
        unset($daysByDate[$knownDay], $daysByDate[$unknownDay]);
        $this->assertSame(
            array_fill(0, 29, [0.0, 0.0, 0.0]),
            array_map(
                static fn (LedgerDayResource $day): array => [$day->mining, $day->total, $day->profit],
                array_values($daysByDate),
            ),
        );
        $this->assertObjectHasProperty('missingPriceTypeIds', $dailyStats);
        $this->assertSame([self::TYPE_WITHOUT_ANY_PRICE], $dailyStats->missingPriceTypeIds);
    }

    public function testAKnownDayAddsPveAndMiningIntoItsTotalAndSubtractsExpensesFromItsProfit(): void
    {
        $miningDay = (new \DateTimeImmutable('-12 days'))->format('Y-m-d');
        $pveIncomeRepository = $this->createStub(PveIncomeRepository::class);
        $pveIncomeRepository->method('getDailyTotalsByType')->willReturn([
            $miningDay => ['bounties' => 60.0, 'lootSales' => 40.0, 'corpProject' => 0.0],
        ]);
        $pveExpenseRepository = $this->createStub(PveExpenseRepository::class);
        $pveExpenseRepository->method('getDailyTotals')->willReturn([
            $miningDay => ['date' => $miningDay, 'total' => 30.0],
        ]);
        $miningEntryRepository = $this->createStub(MiningEntryRepository::class);
        $miningEntryRepository->method('getQuantitiesByTypeAndDate')->willReturn([
            $miningDay => [self::TRITANIUM => 10],
        ]);
        $jitaSellPrices = $this->jitaSellPricesWithoutCompressedOre();
        $jitaSellPrices[self::TRITANIUM] = 5.0;

        $dailyStats = $this->createProvider($pveIncomeRepository, $pveExpenseRepository, $miningEntryRepository, $jitaSellPrices)->provide(new Get());

        $days = array_values(array_filter(
            $dailyStats->daily,
            static fn (LedgerDayResource $day): bool => $day->date === $miningDay,
        ));
        $this->assertCount(1, $days);
        $this->assertSame(
            ['pve' => 100.0, 'mining' => 50.0, 'expenses' => 30.0, 'total' => 150.0, 'profit' => 120.0],
            ['pve' => $days[0]->pve, 'mining' => $days[0]->mining, 'expenses' => $days[0]->expenses, 'total' => $days[0]->total, 'profit' => $days[0]->profit],
        );
        $this->assertSame([], $dailyStats->missingPriceTypeIds);
    }

    private function createProvider(
        PveIncomeRepository $pveIncomeRepository,
        PveExpenseRepository $pveExpenseRepository,
        MiningEntryRepository $miningEntryRepository,
        ?array $jitaSellPrices = null,
    ): LedgerDailyStatsProvider {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(new User());
        $ledgerSettingsRepository = $this->createStub(UserLedgerSettingsRepository::class);
        $ledgerSettingsRepository->method('findByUser')->willReturn(null);
        $requestStack = new RequestStack();
        $requestStack->push(new Request());

        return new LedgerDailyStatsProvider(
            $security,
            $pveIncomeRepository,
            $pveExpenseRepository,
            $ledgerSettingsRepository,
            new MiningBestValueCalculator(
                $miningEntryRepository,
                $this->createOreValueService($jitaSellPrices ?? $this->jitaSellPricesWithoutCompressedOre()),
            ),
            $requestStack,
        );
    }
}
