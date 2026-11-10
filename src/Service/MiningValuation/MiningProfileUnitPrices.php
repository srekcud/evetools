<?php

declare(strict_types=1);

namespace App\Service\MiningValuation;

use App\Entity\User;
use App\Enum\MiningValuationMarket;
use App\Repository\ReprocessingProfileRepository;
use App\Service\JitaMarketService;
use App\Service\OreValueService;
use App\Service\StructureMarketService;

final readonly class MiningProfileUnitPrices
{
    public function __construct(
        private MiningValuationProfileReader $profileReader,
        private ReprocessingProfileRepository $reprocessingProfileRepository,
        private OreValueService $oreValueService,
        private JitaMarketService $jitaMarketService,
        private StructureMarketService $structureMarketService,
    ) {
    }

    /**
     * @param list<int> $typeIds
     */
    public function read(User $user, array $typeIds): MiningProfileUnitPriceReading
    {
        $settings = $this->profileReader->read($user);

        $pricesByTypeId = $this->oreValueService->getBatchOrePrices(
            $typeIds,
            $settings->structureId,
            $this->reprocessingProfileRepository->findOneBy(['user' => $user])?->getReprocessYield(),
            $settings->exportTax,
            $settings->side,
        );

        $priceKey = $settings->form->priceKeyOn($settings->market);
        $unitPricesByTypeId = [];
        foreach ($typeIds as $typeId) {
            $unitPrice = $pricesByTypeId[$typeId][$priceKey];
            $unitPricesByTypeId[$typeId] = $unitPrice === null ? null : (float) $unitPrice;
        }

        return new MiningProfileUnitPriceReading(
            $unitPricesByTypeId,
            $pricesByTypeId,
            new ValuationSource($settings->market, $settings->side, $settings->form, $settings->structureName),
            $this->syncTimeOf($settings),
        );
    }

    private function syncTimeOf(MiningValuationSettings $settings): ?\DateTimeImmutable
    {
        if ($settings->market === MiningValuationMarket::Structure && $settings->structureId !== null) {
            return $this->structureMarketService->getLastSyncTime($settings->structureId);
        }

        return $this->jitaMarketService->getLastSyncTime();
    }
}
