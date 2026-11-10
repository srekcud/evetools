<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Mining;

use App\Entity\MiningValuationProfile;
use App\Enum\MarketSide;
use App\Enum\MiningValuationForm;
use App\Enum\MiningValuationMarket;
use App\Repository\MiningValuationProfileRepository;
use App\Repository\ReprocessingProfileRepository;
use App\Service\JitaMarketService;
use App\Service\MiningValuation\MiningProfileUnitPrices;
use App\Service\MiningValuation\MiningValuationProfileReader;
use App\Service\OreValueService;
use App\Service\StructureMarketService;

trait BuildsMiningProfileUnitPrices
{
    private const int PROFILE_STRUCTURE_ID = 60003760;
    private const string PROFILE_STRUCTURE_NAME = 'Jita IV - Moon 4 - Caldari Navy Assembly Plant';
    private const string JITA_PRICES_READ_AT = '2026-11-10T09:30:00+00:00';
    private const string STRUCTURE_PRICES_READ_AT = '2026-11-10T09:45:00+00:00';

    private function jitaBuyPrices(): array
    {
        return [
            self::VELDSPAR => 15.2,
            self::COMPRESSED_VELDSPAR => 17.5,
            self::BITUMENS => 190.4,
            self::COMPRESSED_BITUMENS => 201.3,
            self::CLEAR_ICICLE => 139400.0,
            self::COMPRESSED_CLEAR_ICICLE => 143800.0,
            self::FULLERITE_C50 => 2594.0,
            self::COMPRESSED_FULLERITE_C50 => 2650.0,
            self::TRITANIUM => 3.9,
            self::PYERITE => 8.7,
            self::MEXALLON => 55.1,
            self::HYDROCARBONS => 330.2,
            self::HEAVY_WATER => 80.3,
            self::LIQUID_OZONE => 104.8,
            self::HELIUM_ISOTOPES => 597.4,
            self::STRONTIUM_CLATHRATES => 1120.0,
            self::TYPE_UNKNOWN_TO_SDE => 1001.5,
        ];
    }

    private function sdeTypesWithOreAOfTheSpec(): array
    {
        $sdeTypes = $this->sdeTypes();
        $sdeTypes[self::VELDSPAR]['volume'] = 0.1;
        $sdeTypes[self::COMPRESSED_VELDSPAR]['volume'] = 0.1;

        return $sdeTypes;
    }

    private function jitaBuyPricesOfOreAOfTheSpec(): array
    {
        return [self::COMPRESSED_VELDSPAR => 500.0, self::VELDSPAR => 450.0, self::TRITANIUM => 162.5];
    }

    private function jitaSellPricesOfOreAOfTheSpec(): array
    {
        return [self::COMPRESSED_VELDSPAR => 560.0, self::VELDSPAR => 505.0, self::TRITANIUM => 170.0];
    }

    private function miningValuationProfileOf(
        MiningValuationMarket $market = MiningValuationMarket::Jita,
        MarketSide $side = MarketSide::Buy,
        MiningValuationForm $form = MiningValuationForm::Compressed,
        ?int $structureId = null,
        ?string $structureName = null,
        float $exportTax = 0.0,
    ): MiningValuationProfile {
        return (new MiningValuationProfile())
            ->setMarket($market)
            ->setSide($side)
            ->setForm($form)
            ->setStructureId($structureId)
            ->setStructureName($structureName)
            ->setExportTax($exportTax);
    }

    private function miningValuationProfileRepositoryReturning(?MiningValuationProfile $profile): MiningValuationProfileRepository
    {
        $repository = $this->createStub(MiningValuationProfileRepository::class);
        $repository->method('findOneBy')->willReturn($profile);

        return $repository;
    }

    private function jitaMarketReadAt(array $jitaBuyPrices, array $jitaSellPrices, ?string $readAt = self::JITA_PRICES_READ_AT): JitaMarketService
    {
        $jitaMarketService = $this->createStub(JitaMarketService::class);
        $jitaMarketService->method('getPrices')->willReturnCallback(
            static fn (array $typeIds): array => self::pricesOf($typeIds, $jitaSellPrices),
        );
        $jitaMarketService->method('getBuyPrices')->willReturnCallback(
            static fn (array $typeIds): array => self::pricesOf($typeIds, $jitaBuyPrices),
        );
        $jitaMarketService->method('getLastSyncTime')->willReturn($readAt === null ? null : new \DateTimeImmutable($readAt));

        return $jitaMarketService;
    }

    private function createMiningProfileUnitPrices(
        ?array $jitaBuyPrices = null,
        ?array $jitaSellPrices = null,
        ?MiningValuationProfile $profile = null,
        ?ReprocessingProfileRepository $reprocessingProfileRepository = null,
        ?StructureMarketService $structureMarketService = null,
        ?array $sdeTypes = null,
        ?MiningValuationProfileRepository $profileRepository = null,
    ): MiningProfileUnitPrices {
        $jitaMarketService = $this->jitaMarketReadAt($jitaBuyPrices ?? $this->jitaBuyPrices(), $jitaSellPrices ?? $this->jitaSellPrices());
        $structureMarketService ??= $this->createStub(StructureMarketService::class);
        $reprocessingProfileRepository ??= $this->profileRepositoryWithYield(0.78);

        return new MiningProfileUnitPrices(
            new MiningValuationProfileReader($profileRepository ?? $this->miningValuationProfileRepositoryReturning($profile)),
            $reprocessingProfileRepository,
            new OreValueService(
                $this->sdeInvTypeRepository($sdeTypes ?? $this->sdeTypes(), []),
                $this->sdeInvTypeMaterialRepository($this->sdeReprocessMaterials()),
                $jitaMarketService,
                $structureMarketService,
            ),
            $jitaMarketService,
            $structureMarketService,
        );
    }

    private function structureMarketReadAt(int $structureId, array $structureSellPrices, array $structureBuyPrices): StructureMarketService
    {
        $structureMarketService = $this->structureMarketTrading($structureId, $structureSellPrices, $structureBuyPrices);
        $structureMarketService->method('getLastSyncTime')->willReturnCallback(
            static fn (int $requestedStructureId): ?\DateTimeImmutable => $requestedStructureId === $structureId ? new \DateTimeImmutable(self::STRUCTURE_PRICES_READ_AT) : null,
        );

        return $structureMarketService;
    }
}
