<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Ledger;

use ApiPlatform\Metadata\Get;
use App\ApiResource\Ledger\MiningTypeStatsResource;
use App\Entity\User;
use App\Repository\MiningEntryRepository;
use App\Repository\UserLedgerSettingsRepository;
use App\Service\MiningBestValueCalculator;
use App\State\Provider\Ledger\MiningStatsByTypeProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

#[CoversClass(MiningStatsByTypeProvider::class)]
final class MiningStatsByTypeProviderTest extends TestCase
{
    private const int PYROXERES = 1224;
    private const int ORE_MISSING_FROM_SDE = 62568;

    public function testStatsOfAnOreMissingFromTheSdeHaveAnUnknownNameAndUnchangedTotals(): void
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(new User());
        $miningEntryRepository = $this->createStub(MiningEntryRepository::class);
        $miningEntryRepository->method('getTotalsByType')->willReturn([
            self::PYROXERES => ['typeId' => self::PYROXERES, 'typeName' => 'Pyroxeres', 'totalValue' => 0.0, 'totalQuantity' => 431],
            self::ORE_MISSING_FROM_SDE => ['typeId' => self::ORE_MISSING_FROM_SDE, 'typeName' => null, 'totalValue' => 0.0, 'totalQuantity' => 15_587],
        ]);
        $settingsRepository = $this->createStub(UserLedgerSettingsRepository::class);
        $settingsRepository->method('findByUser')->willReturn(null);
        $miningBestValueCalculator = $this->createStub(MiningBestValueCalculator::class);
        $miningBestValueCalculator->method('getBestUnitPrices')->willReturn([
            self::PYROXERES => 512.0,
            self::ORE_MISSING_FROM_SDE => 41.5,
        ]);
        $requestStack = new RequestStack();
        $requestStack->push(new Request());
        $provider = new MiningStatsByTypeProvider($security, $miningEntryRepository, $settingsRepository, $miningBestValueCalculator, $requestStack);

        $stats = $provider->provide(new Get());

        $this->assertSame([
            [self::ORE_MISSING_FROM_SDE, null, 15_587, 646_860.5],
            [self::PYROXERES, 'Pyroxeres', 431, 220_672.0],
        ], array_map(static fn (MiningTypeStatsResource $typeStats): array => [
            $typeStats->typeId,
            $typeStats->typeName,
            $typeStats->totalQuantity,
            $typeStats->totalValue,
        ], $stats->byType));
    }
}
