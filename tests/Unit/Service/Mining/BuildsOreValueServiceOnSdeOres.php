<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Mining;

use App\Entity\Sde\InvType;
use App\Entity\Sde\InvTypeMaterial;
use App\Repository\Sde\InvTypeMaterialRepository;
use App\Repository\Sde\InvTypeRepository;
use App\Service\JitaMarketService;
use App\Service\OreValueService;
use App\Service\StructureMarketService;

trait BuildsOreValueServiceOnSdeOres
{
    private const int VELDSPAR = 1230;
    private const int COMPRESSED_VELDSPAR = 62516;
    private const int BITUMENS = 45492;
    private const int COMPRESSED_BITUMENS = 62454;
    private const int CLEAR_ICICLE = 16262;
    private const int COMPRESSED_CLEAR_ICICLE = 28434;
    private const int FULLERITE_C50 = 30370;
    private const int COMPRESSED_FULLERITE_C50 = 62399;
    private const int TRITANIUM = 34;
    private const int PYERITE = 35;
    private const int MEXALLON = 36;
    private const int HYDROCARBONS = 16633;
    private const int HEAVY_WATER = 16272;
    private const int LIQUID_OZONE = 16273;
    private const int HELIUM_ISOTOPES = 16274;
    private const int STRONTIUM_CLATHRATES = 16275;
    private const int TYPE_UNKNOWN_TO_SDE = 87161;

    private function sdeTypes(): array
    {
        return [
            self::VELDSPAR => ['name' => 'Veldspar', 'volume' => 0.1, 'portionSize' => 100],
            self::COMPRESSED_VELDSPAR => ['name' => 'Compressed Veldspar', 'volume' => 0.001, 'portionSize' => 100],
            self::BITUMENS => ['name' => 'Bitumens', 'volume' => 10.0, 'portionSize' => 100],
            self::COMPRESSED_BITUMENS => ['name' => 'Compressed Bitumens', 'volume' => 0.1, 'portionSize' => 100],
            self::CLEAR_ICICLE => ['name' => 'Clear Icicle', 'volume' => 1000.0, 'portionSize' => 1],
            self::COMPRESSED_CLEAR_ICICLE => ['name' => 'Compressed Clear Icicle', 'volume' => 100.0, 'portionSize' => 1],
            self::FULLERITE_C50 => ['name' => 'Fullerite-C50', 'volume' => 1.0, 'portionSize' => 1],
            self::COMPRESSED_FULLERITE_C50 => ['name' => 'Compressed Fullerite-C50', 'volume' => 0.1, 'portionSize' => 1],
            self::TRITANIUM => ['name' => 'Tritanium', 'volume' => 0.01, 'portionSize' => 1],
            self::PYERITE => ['name' => 'Pyerite', 'volume' => 0.01, 'portionSize' => 1],
            self::MEXALLON => ['name' => 'Mexallon', 'volume' => 0.01, 'portionSize' => 1],
            self::HYDROCARBONS => ['name' => 'Hydrocarbons', 'volume' => 0.05, 'portionSize' => 1],
            self::HEAVY_WATER => ['name' => 'Heavy Water', 'volume' => 0.4, 'portionSize' => 1],
            self::LIQUID_OZONE => ['name' => 'Liquid Ozone', 'volume' => 0.4, 'portionSize' => 1],
            self::HELIUM_ISOTOPES => ['name' => 'Helium Isotopes', 'volume' => 0.03, 'portionSize' => 1],
            self::STRONTIUM_CLATHRATES => ['name' => 'Strontium Clathrates', 'volume' => 3.0, 'portionSize' => 1],
        ];
    }

    private function sdeReprocessMaterials(): array
    {
        $veldspar = [self::TRITANIUM => 400];
        $bitumens = [self::PYERITE => 6000, self::MEXALLON => 400, self::HYDROCARBONS => 65];
        $clearIcicle = [
            self::HEAVY_WATER => 69,
            self::LIQUID_OZONE => 35,
            self::HELIUM_ISOTOPES => 414,
            self::STRONTIUM_CLATHRATES => 1,
        ];

        return [
            self::VELDSPAR => $veldspar,
            self::COMPRESSED_VELDSPAR => $veldspar,
            self::BITUMENS => $bitumens,
            self::COMPRESSED_BITUMENS => $bitumens,
            self::CLEAR_ICICLE => $clearIcicle,
            self::COMPRESSED_CLEAR_ICICLE => $clearIcicle,
            self::COMPRESSED_FULLERITE_C50 => [self::FULLERITE_C50 => 1],
        ];
    }

    private function jitaSellPrices(): array
    {
        return [
            self::VELDSPAR => 18.37,
            self::COMPRESSED_VELDSPAR => 19.61,
            self::BITUMENS => 213.9,
            self::COMPRESSED_BITUMENS => 221.45,
            self::CLEAR_ICICLE => 152300.0,
            self::COMPRESSED_CLEAR_ICICLE => 158700.0,
            self::FULLERITE_C50 => 2741.0,
            self::COMPRESSED_FULLERITE_C50 => 2813.0,
            self::TRITANIUM => 4.27,
            self::PYERITE => 9.83,
            self::MEXALLON => 61.7,
            self::HYDROCARBONS => 352.4,
            self::HEAVY_WATER => 87.6,
            self::LIQUID_OZONE => 113.9,
            self::HELIUM_ISOTOPES => 641.3,
            self::STRONTIUM_CLATHRATES => 1205.0,
            self::TYPE_UNKNOWN_TO_SDE => 1093.5,
        ];
    }

    private function createOreValueService(
        array $jitaSellPrices,
        ?StructureMarketService $structureMarketService = null,
        ?array $sdeTypes = null,
    ): OreValueService {
        $invTypes = [];
        foreach ($sdeTypes ?? $this->sdeTypes() as $typeId => $sdeType) {
            $invTypes[$typeId] = (new InvType())
                ->setTypeId($typeId)
                ->setTypeName($sdeType['name'])
                ->setVolume($sdeType['volume'])
                ->setPortionSize($sdeType['portionSize']);
        }

        $invTypeRepository = $this->createStub(InvTypeRepository::class);
        $invTypeRepository->method('find')->willReturnCallback(
            static fn (mixed $typeId): ?InvType => $invTypes[$typeId] ?? null,
        );
        $invTypeRepository->method('findOneBy')->willReturnCallback(
            static function (array $criteria) use ($invTypes): ?InvType {
                foreach ($invTypes as $invType) {
                    if ($invType->getTypeName() === $criteria['typeName']) {
                        return $invType;
                    }
                }

                return null;
            },
        );

        $reprocessMaterials = $this->sdeReprocessMaterials();
        $invTypeMaterialRepository = $this->createStub(InvTypeMaterialRepository::class);
        $invTypeMaterialRepository->method('findByTypeIds')->willReturnCallback(
            static function (array $typeIds) use ($reprocessMaterials): array {
                $materialsByType = [];
                foreach ($typeIds as $typeId) {
                    foreach ($reprocessMaterials[$typeId] ?? [] as $materialTypeId => $quantity) {
                        $materialsByType[$typeId][] = (new InvTypeMaterial())
                            ->setTypeId($typeId)
                            ->setMaterialTypeId($materialTypeId)
                            ->setQuantity($quantity);
                    }
                }

                return $materialsByType;
            },
        );

        $jitaMarketService = $this->createStub(JitaMarketService::class);
        $jitaMarketService->method('getPrices')->willReturnCallback(
            static fn (array $typeIds): array => array_combine(
                $typeIds,
                array_map(static fn (int $typeId): ?float => $jitaSellPrices[$typeId] ?? null, $typeIds),
            ),
        );

        return new OreValueService(
            $invTypeRepository,
            $invTypeMaterialRepository,
            $jitaMarketService,
            $structureMarketService,
        );
    }

    private function oreValueServiceThatMustNotLookUpAnything(): OreValueService
    {
        $invTypeMaterialRepository = $this->createMock(InvTypeMaterialRepository::class);
        $invTypeMaterialRepository->expects($this->never())->method('findByTypeIds');
        $jitaMarketService = $this->createMock(JitaMarketService::class);
        $jitaMarketService->expects($this->never())->method('getPrices');

        return new OreValueService(
            $this->createStub(InvTypeRepository::class),
            $invTypeMaterialRepository,
            $jitaMarketService,
            null,
        );
    }

    private function structureMarketSelling(int $structureId, array $structureSellPrices): StructureMarketService
    {
        $structureMarketService = $this->createStub(StructureMarketService::class);
        $structureMarketService->method('getLowestSellPrices')->willReturnCallback(
            static fn (int $requestedStructureId, array $typeIds): array => array_combine(
                $typeIds,
                array_map(
                    static fn (int $typeId): ?float => $requestedStructureId === $structureId
                        ? ($structureSellPrices[$typeId] ?? null)
                        : null,
                    $typeIds,
                ),
            ),
        );

        return $structureMarketService;
    }
}
