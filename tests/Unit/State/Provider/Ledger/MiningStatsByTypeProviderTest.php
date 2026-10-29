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
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

#[CoversClass(MiningStatsByTypeProvider::class)]
final class MiningStatsByTypeProviderTest extends TestCase
{
    private const int PYROXERES = 1224;
    private const int ORE_MISSING_FROM_SDE = 62568;
    private const int BITUMENS = 45492;
    private const int TRITANIUM = 34;
    private const int FULLERITE_C50 = 30370;
    private const int TYPE_WITHOUT_ANY_PRICE = 81143;
    private const float VALUE_DELTA = 1e-6;

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

    #[Group('issue-260')]
    public function testATypeWithoutPriceHasAnUnknownValueAndComesAfterEveryKnownValue(): void
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(new User());
        $miningEntryRepository = $this->createStub(MiningEntryRepository::class);
        $miningEntryRepository->method('getTotalsByType')->willReturn([
            self::TYPE_WITHOUT_ANY_PRICE => ['typeId' => self::TYPE_WITHOUT_ANY_PRICE, 'typeName' => null, 'totalValue' => 0.0, 'totalQuantity' => 3150],
            self::FULLERITE_C50 => ['typeId' => self::FULLERITE_C50, 'typeName' => 'Fullerite-C50', 'totalValue' => 0.0, 'totalQuantity' => 2290],
            self::TRITANIUM => ['typeId' => self::TRITANIUM, 'typeName' => 'Tritanium', 'totalValue' => 0.0, 'totalQuantity' => 2500],
            self::BITUMENS => ['typeId' => self::BITUMENS, 'typeName' => 'Bitumens', 'totalValue' => 0.0, 'totalQuantity' => 1000],
        ]);
        $settingsRepository = $this->createStub(UserLedgerSettingsRepository::class);
        $settingsRepository->method('findByUser')->willReturn(null);
        $miningBestValueCalculator = $this->createStub(MiningBestValueCalculator::class);
        $miningBestValueCalculator->method('getBestUnitPrices')->willReturn([
            self::TYPE_WITHOUT_ANY_PRICE => null,
            self::FULLERITE_C50 => 0.0,
            self::TRITANIUM => 4.27,
            self::BITUMENS => 831.2148,
        ]);
        $requestStack = new RequestStack();
        $requestStack->push(new Request());
        $provider = new MiningStatsByTypeProvider($security, $miningEntryRepository, $settingsRepository, $miningBestValueCalculator, $requestStack);

        $byType = $provider->provide(new Get())->byType;

        $this->assertSame(
            [[self::BITUMENS, 1000], [self::TRITANIUM, 2500], [self::FULLERITE_C50, 2290], [self::TYPE_WITHOUT_ANY_PRICE, 3150]],
            array_map(static fn (MiningTypeStatsResource $typeStats): array => [
                $typeStats->typeId,
                $typeStats->totalQuantity,
            ], $byType),
        );
        $this->assertEqualsWithDelta(831214.8, $byType[0]->totalValue, self::VALUE_DELTA);
        $this->assertEqualsWithDelta(10675.0, $byType[1]->totalValue, self::VALUE_DELTA);
        $this->assertSame(0.0, $byType[2]->totalValue);
        $this->assertNull($byType[3]->totalValue);
    }
}
