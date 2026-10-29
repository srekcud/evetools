<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Ledger;

use ApiPlatform\Metadata\Get;
use App\Entity\MiningEntry;
use App\Entity\User;
use App\Repository\MiningEntryRepository;
use App\Repository\UserLedgerSettingsRepository;
use App\Service\MiningBestValueCalculator;
use App\State\Provider\Ledger\MiningStatsProvider;
use App\Tests\Unit\Service\Mining\BuildsOreValueServiceOnSdeOres;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

#[CoversClass(MiningStatsProvider::class)]
#[Group('issue-260')]
final class MiningStatsProviderTest extends TestCase
{
    use BuildsOreValueServiceOnSdeOres;

    private const float VALUE_DELTA = 1e-6;
    private const int TYPE_WITHOUT_ANY_PRICE = 81143;

    public function testMiningStatsAreUnknownWhenAMinedTypeHasNoPrice(): void
    {
        $miningEntryRepository = $this->createStub(MiningEntryRepository::class);
        $miningEntryRepository->method('getTotalQuantityByUserAndDateRange')->willReturn(4150);
        $miningEntryRepository->method('getQuantitiesByType')->willReturn([
            self::BITUMENS => ['typeId' => self::BITUMENS, 'quantity' => 1000],
            self::TYPE_WITHOUT_ANY_PRICE => ['typeId' => self::TYPE_WITHOUT_ANY_PRICE, 'quantity' => 3150],
        ]);
        $miningEntryRepository->method('getQuantitiesByTypeAndUsage')->willReturn([
            MiningEntry::USAGE_SOLD => [self::BITUMENS => 1000],
            MiningEntry::USAGE_INDUSTRY => [self::TRITANIUM => 2500, self::TYPE_WITHOUT_ANY_PRICE => 3150],
        ]);

        $stats = $this->createProvider($miningEntryRepository)->provide(new Get());

        $this->assertNull($stats->totals['totalValue']);
        $this->assertSame(4150, $stats->totals['totalQuantity']);
        $this->assertNull($stats->iskPerDay);
        $this->assertUsageTotals(
            [
                MiningEntry::USAGE_SOLD => [831214.8, 1000, []],
                MiningEntry::USAGE_INDUSTRY => [null, 5650, [self::TYPE_WITHOUT_ANY_PRICE]],
            ],
            $stats->byUsage,
        );
        $this->assertObjectHasProperty('missingPriceTypeIds', $stats);
        $this->assertSame([self::TYPE_WITHOUT_ANY_PRICE], $stats->missingPriceTypeIds);
    }

    public function testMiningStatsAreKnownWhenEveryMinedTypeHasAPrice(): void
    {
        $miningEntryRepository = $this->createStub(MiningEntryRepository::class);
        $miningEntryRepository->method('getTotalQuantityByUserAndDateRange')->willReturn(3500);
        $miningEntryRepository->method('getQuantitiesByType')->willReturn([
            self::BITUMENS => ['typeId' => self::BITUMENS, 'quantity' => 1000],
            self::TRITANIUM => ['typeId' => self::TRITANIUM, 'quantity' => 2500],
        ]);
        $miningEntryRepository->method('getQuantitiesByTypeAndUsage')->willReturn([
            MiningEntry::USAGE_SOLD => [self::BITUMENS => 1000],
            MiningEntry::USAGE_INDUSTRY => [self::TRITANIUM => 2500],
        ]);

        $stats = $this->createProvider($miningEntryRepository)->provide(new Get());

        $this->assertEqualsWithDelta(841889.8, $stats->totals['totalValue'], self::VALUE_DELTA);
        $this->assertSame(3500, $stats->totals['totalQuantity']);
        $this->assertEqualsWithDelta(28062.993333333, $stats->iskPerDay, self::VALUE_DELTA);
        $this->assertUsageTotals(
            [
                MiningEntry::USAGE_SOLD => [831214.8, 1000, []],
                MiningEntry::USAGE_INDUSTRY => [10675.0, 2500, []],
            ],
            $stats->byUsage,
        );
        $this->assertObjectHasProperty('missingPriceTypeIds', $stats);
        $this->assertSame([], $stats->missingPriceTypeIds);
    }

    public function testIskPerDayIsAnExactZeroWithoutDays(): void
    {
        $miningEntryRepository = $this->createStub(MiningEntryRepository::class);
        $miningEntryRepository->method('getTotalQuantityByUserAndDateRange')->willReturn(1000);
        $miningEntryRepository->method('getQuantitiesByType')->willReturn([
            self::BITUMENS => ['typeId' => self::BITUMENS, 'quantity' => 1000],
        ]);
        $miningEntryRepository->method('getQuantitiesByTypeAndUsage')->willReturn([]);

        $stats = $this->createProvider($miningEntryRepository, ['days' => '0'])->provide(new Get());

        $this->assertEqualsWithDelta(831214.8, $stats->totals['totalValue'], self::VALUE_DELTA);
        $this->assertSame(0.0, $stats->iskPerDay);
    }

    private function createProvider(MiningEntryRepository $miningEntryRepository, array $query = []): MiningStatsProvider
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(new User());
        $settingsRepository = $this->createStub(UserLedgerSettingsRepository::class);
        $settingsRepository->method('findByUser')->willReturn(null);
        $requestStack = new RequestStack();
        $requestStack->push(new Request($query));

        return new MiningStatsProvider(
            $security,
            $miningEntryRepository,
            $settingsRepository,
            new MiningBestValueCalculator(
                $miningEntryRepository,
                $this->createOreValueService($this->jitaSellPricesWithoutCompressedOre()),
            ),
            $requestStack,
        );
    }

    private function assertUsageTotals(array $expectedTotalsByUsage, array $totalsByUsage): void
    {
        $this->assertSame(array_keys($expectedTotalsByUsage), array_keys($totalsByUsage));
        foreach ($expectedTotalsByUsage as $usage => [$expectedTotalValue, $expectedTotalQuantity, $expectedMissingPriceTypeIds]) {
            $this->assertSame(['totalValue', 'totalQuantity', 'missingPriceTypeIds'], array_keys($totalsByUsage[$usage]));
            if ($expectedTotalValue === null) {
                $this->assertNull($totalsByUsage[$usage]['totalValue']);
            } else {
                $this->assertEqualsWithDelta($expectedTotalValue, $totalsByUsage[$usage]['totalValue'], self::VALUE_DELTA);
            }
            $this->assertSame($expectedTotalQuantity, $totalsByUsage[$usage]['totalQuantity']);
            $this->assertSame($expectedMissingPriceTypeIds, $totalsByUsage[$usage]['missingPriceTypeIds']);
        }
    }
}
