<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\MiningOperation;

use App\Entity\CachedAsset;
use App\Entity\CorpAssetVisibility;
use App\Entity\MiningOperation;
use App\Entity\User;
use App\Enum\MarketSide;
use App\Enum\MiningFleetStorageDelivery;
use App\Repository\CachedAssetRepository;
use App\Repository\CorpAssetVisibilityRepository;
use App\Service\Assets\VisibleCorporationAssetsFinder;
use App\Service\MiningOperation\MinedQuantityCalculator;
use App\Service\MiningOperation\MiningFleetStorageVerifier;
use App\Service\OreValueService;
use PHPUnit\Framework\MockObject\MockObject;

trait BuildsVisibleCorporationAssets
{
    private const string CONTAINER_NAME = 'OPS-HAUL';
    private const string ASSETS_READ_AT = '2026-11-05T21:41:00+00:00';
    private const string ASSETS_READ_BEFORE_END_AT = '2026-11-05T20:40:00+00:00';
    private const int STATION_ID = 60_008_494;
    private const int STATION_CONTAINER = 17366;
    private const int OPS_HAUL_ITEM_ID = 1_051_000_001;
    private const int SECOND_OPS_HAUL_ITEM_ID = 1_051_000_002;
    private const int BIN_ITEM_ID = 1_051_000_003;
    private const array VISIBLE_DIVISIONS = [1, 2];

    private CachedAssetRepository&MockObject $cachedAssetRepository;
    private CorpAssetVisibilityRepository&MockObject $visibilityRepository;
    private ?CorpAssetVisibility $visibility;
    private bool $corporationAssetsSynced = true;
    private array $hangarAssets = [];
    private array $containerContents = [];
    private int $nextItemId = 1_052_000_001;

    private function setUpVisibleCorporationAssets(): void
    {
        $this->visibility = (new CorpAssetVisibility())->setCorporationId(self::C1)->setVisibleDivisions(self::VISIBLE_DIVISIONS);
        $this->cachedAssetRepository = $this->createMock(CachedAssetRepository::class);
        $this->cachedAssetRepository->method('hasCorporationAssets')->willReturnCallback(fn (int $corporationId): bool => $corporationId === self::C1 && $this->corporationAssetsSynced);
        $this->cachedAssetRepository->method('findByCorporationAndDivisions')->willReturnCallback(fn (): array => $this->hangarAssets);
        $this->cachedAssetRepository->method('findContentsOfContainers')->willReturnCallback(fn (): array => $this->containerContents);
        $this->visibilityRepository = $this->createMock(CorpAssetVisibilityRepository::class);
        $this->visibilityRepository->method('findByCorporationId')->willReturnCallback(fn (int $corporationId): ?CorpAssetVisibility => $corporationId === self::C1 ? $this->visibility : null);
    }

    private function fs2Operation(array $unreadStarts = []): MiningOperation
    {
        $operation = $this->e1Operation(unreadStarts: $unreadStarts);
        $operation->updateValuationSettings(valuationSide: MarketSide::Sell, boosterCutPercent: 10, boosterMember: $this->membershipOf($operation, $this->cyra));
        $operation->assignFleetStorage($this->membershipOf($operation, $this->brann), self::CONTAINER_NAME);
        $this->declare($operation, $this->aria, MiningFleetStorageDelivery::Partial, [self::VELDSPAR => 100_000]);

        return $operation;
    }

    private function declare(MiningOperation $operation, User $participant, MiningFleetStorageDelivery $delivery, ?array $quantities = null): void
    {
        $this->membershipOf($operation, $participant)->declareFleetStorage($delivery, $quantities);
    }

    private function opsHaulHolding(array $quantitiesByTypeId, string $cachedAt = self::ASSETS_READ_AT): void
    {
        $this->hangarAssets = [$this->container(self::OPS_HAUL_ITEM_ID, self::CONTAINER_NAME, self::STATION_ID, $cachedAt)];
        $this->containerContents = [];
        foreach ($quantitiesByTypeId as $typeId => $quantity) {
            $this->containerContents[] = $this->item($typeId, $quantity, self::OPS_HAUL_ITEM_ID, $cachedAt);
        }
    }

    private function container(int $itemId, string $name, int $locationId = self::STATION_ID, string $cachedAt = self::ASSETS_READ_AT): CachedAsset
    {
        return $this->asset($itemId, self::STATION_CONTAINER, 1, $locationId, $cachedAt)->setItemName($name)->setLocationFlag('CorpSAG1');
    }

    private function item(int $typeId, int $quantity, int $locationId, string $cachedAt = self::ASSETS_READ_AT): CachedAsset
    {
        return $this->asset($this->nextItemId++, $typeId, $quantity, $locationId, $cachedAt);
    }

    private function asset(int $itemId, int $typeId, int $quantity, int $locationId, string $cachedAt): CachedAsset
    {
        return (new CachedAsset())
            ->setItemId($itemId)
            ->setTypeId($typeId)
            ->setQuantity($quantity)
            ->setLocationId($locationId)
            ->setLocationType($locationId === self::STATION_ID ? 'station' : 'item')
            ->setLocationName('Aldranette VI - Moon 1 - Federal Navy Academy')
            ->setCorporationId(self::C1)
            ->setIsCorporationAsset(true)
            ->setCachedAt(new \DateTimeImmutable($cachedAt));
    }

    private function expectNoCorporationAssetRead(): void
    {
        $this->cachedAssetRepository->expects($this->never())->method('hasCorporationAssets');
        $this->cachedAssetRepository->expects($this->never())->method('findByCorporationAndDivisions');
        $this->cachedAssetRepository->expects($this->never())->method('findContentsOfContainers');
        $this->visibilityRepository->expects($this->never())->method('findByCorporationId');
    }

    private function verifier(?OreValueService $oreValueService = null): MiningFleetStorageVerifier
    {
        return new MiningFleetStorageVerifier(
            new VisibleCorporationAssetsFinder($this->cachedAssetRepository, $this->visibilityRepository),
            $this->cachedAssetRepository,
            $this->visibilityRepository,
            $oreValueService ?? $this->oreValueServiceOn($this->jitaMarketSyncedAt($this->jitaSellPrices())),
            new MinedQuantityCalculator(),
            $this->typeNameResolverOfSdeOres(),
        );
    }
}
