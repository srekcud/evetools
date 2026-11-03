<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Mining;

use App\Entity\Sde\InvCategory;
use App\Entity\Sde\InvGroup;
use App\Entity\Sde\InvType;
use App\Entity\User;
use App\Repository\CachedAssetRepository;
use App\Repository\Sde\InvTypeRepository;
use App\Service\JitaMarketService;
use App\Service\Mining\OreSaleService;
use App\Service\StructureMarketService;

trait BuildsOreSaleServiceOnHeldOres
{
    private const int COMPRESSED_VELDSPAR = 62516;
    private const int COMPRESSED_BITUMENS = 62454;
    private const int COMPRESSED_CLEAR_ICICLE = 28434;
    private const int COMPRESSED_FULLERITE_C50 = 62399;
    private const int TRITANIUM = 34;
    private const int BATCH_COMPRESSED_VELDSPAR = 28432;
    private const int TYPE_UNKNOWN_TO_SDE = 87161;
    private const int SALE_STRUCTURE_ID = 1_000_000_000_001;
    private const int OTHER_STRUCTURE_ID = 1_000_000_000_002;

    private function compressedMineableTypes(): array
    {
        return [
            self::COMPRESSED_VELDSPAR => ['Compressed Veldspar', 0.001, 462, 'Veldspar', 25],
            self::COMPRESSED_BITUMENS => ['Compressed Bitumens', 0.1, 1884, 'Ubiquitous Moon Asteroids', 25],
            self::COMPRESSED_CLEAR_ICICLE => ['Compressed Clear Icicle', 100.0, 465, 'Ice', 25],
            self::COMPRESSED_FULLERITE_C50 => ['Compressed Fullerite-C50', 0.1, 4168, 'Compressed Gas', 2],
        ];
    }

    private function createOreSaleService(
        User $owner,
        array $heldQuantities,
        array $jitaBuyPrices,
        array $jitaSellPrices,
        array $structureBuyPrices = [],
        array $structureSellPrices = [],
        array $unitVolumes = [],
    ): OreSaleService {
        return new OreSaleService(
            $this->personalAssetsOf($owner, $heldQuantities),
            $this->compressedMineableTypeRepository($unitVolumes),
            $this->jitaMarketWithFallback($jitaBuyPrices, $jitaSellPrices),
            $this->saleStructureMarket($structureBuyPrices, $structureSellPrices),
        );
    }

    private function personalAssetsOf(User $owner, array $heldQuantities): CachedAssetRepository
    {
        $cachedAssetRepository = $this->createMock(CachedAssetRepository::class);
        $cachedAssetRepository->expects($this->once())
            ->method('getAggregatedQuantitiesByUser')
            ->with($this->identicalTo($owner))
            ->willReturn($heldQuantities);

        return $cachedAssetRepository;
    }

    private function compressedMineableTypeRepository(array $unitVolumes): InvTypeRepository
    {
        $invTypes = [];
        foreach ($this->compressedMineableTypes() as $typeId => [$typeName, $volume, $groupId, $groupName, $categoryId]) {
            $invTypes[$typeId] = (new InvType())
                ->setTypeId($typeId)
                ->setTypeName($typeName)
                ->setVolume(array_key_exists($typeId, $unitVolumes) ? $unitVolumes[$typeId] : $volume)
                ->setPortionSize(1)
                ->setPublished(true)
                ->setGroup(
                    (new InvGroup())
                        ->setGroupId($groupId)
                        ->setGroupName($groupName)
                        ->setCategory((new InvCategory())->setCategoryId($categoryId)->setCategoryName('Category ' . $categoryId)),
                );
        }

        $invTypeRepository = $this->createStub(InvTypeRepository::class);
        $invTypeRepository->method('findCompressedMineableTypes')->willReturnCallback(
            static fn (array $typeIds): array => array_values(array_filter(
                $invTypes,
                static fn (int $typeId): bool => in_array($typeId, $typeIds, true),
                ARRAY_FILTER_USE_KEY,
            )),
        );

        return $invTypeRepository;
    }

    private function jitaMarketWithFallback(array $jitaBuyPrices, array $jitaSellPrices): JitaMarketService
    {
        $jitaMarketService = $this->createStub(JitaMarketService::class);
        $jitaMarketService->method('getBuyPricesWithFallback')->willReturnCallback(
            static fn (array $typeIds): array => self::salePricesOf($typeIds, $jitaBuyPrices),
        );
        $jitaMarketService->method('getPricesWithFallback')->willReturnCallback(
            static fn (array $typeIds): array => self::salePricesOf($typeIds, $jitaSellPrices),
        );

        return $jitaMarketService;
    }

    private function saleStructureMarket(array $structureBuyPrices, array $structureSellPrices): StructureMarketService
    {
        $pricesAt = static fn (array $prices): \Closure => static fn (int $structureId, array $typeIds): array => self::salePricesOf(
            $typeIds,
            $structureId === self::SALE_STRUCTURE_ID ? $prices : [],
        );
        $structureMarketService = $this->createStub(StructureMarketService::class);
        $structureMarketService->method('getHighestBuyPrices')->willReturnCallback($pricesAt($structureBuyPrices));
        $structureMarketService->method('getLowestSellPrices')->willReturnCallback($pricesAt($structureSellPrices));

        return $structureMarketService;
    }

    private static function salePricesOf(array $typeIds, array $prices): array
    {
        $typeIds = array_values($typeIds);

        return array_combine(
            $typeIds,
            array_map(static fn (int $typeId): ?float => $prices[$typeId] ?? null, $typeIds),
        );
    }
}
