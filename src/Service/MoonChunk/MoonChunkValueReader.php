<?php

declare(strict_types=1);

namespace App\Service\MoonChunk;

use App\Entity\CorporationMiningExtraction;
use App\Entity\MoonComposition;
use App\Entity\User;
use App\Entity\Sde\InvType;
use App\Enum\MoonChunkStatus;
use App\Repository\MoonCompositionRepository;
use App\Repository\Sde\InvTypeRepository;
use App\Service\MiningValuation\MiningProfileUnitPrices;

final readonly class MoonChunkValueReader
{
    public function __construct(
        private MoonCompositionRepository $compositionRepository,
        private InvTypeRepository $invTypeRepository,
        private MiningProfileUnitPrices $unitPrices,
        private MoonChunkVolumeEstimator $volumeEstimator,
        private MoonChunkValuer $valuer,
    ) {
    }

    /**
     * @param list<CorporationMiningExtraction> $extractions
     *
     * @return \SplObjectStorage<CorporationMiningExtraction, array<string, mixed>> one entry per chunk that is not exploded
     */
    public function read(User $user, array $extractions, \DateTimeImmutable $now): \SplObjectStorage
    {
        $valuedExtractions = array_values(array_filter(
            $extractions,
            static fn (CorporationMiningExtraction $extraction): bool => MoonChunkStatus::fromTimes($now, $extraction->getChunkArrivalTime(), $extraction->getNaturalDecayTime()) !== MoonChunkStatus::Exploded,
        ));
        $compositions = $this->compositionsOf($valuedExtractions);
        $typeIds = $this->typeIdsOf($compositions);
        $types = $typeIds === [] ? [] : $this->invTypeRepository->findByTypeIds($typeIds);
        $unitPrices = $typeIds === [] ? [] : $this->unitPrices->read($user, $typeIds)->unitPricesByTypeId;
        $unitVolumes = array_map(static fn (?InvType $type): ?float => $type?->getVolume(), array_replace(array_fill_keys($typeIds, null), $types));

        /** @var \SplObjectStorage<CorporationMiningExtraction, array<string, mixed>> $views */
        $views = new \SplObjectStorage();
        foreach ($valuedExtractions as $extraction) {
            $composition = $compositions[$extraction->getCorporationId()][$extraction->getMoonId()] ?? null;
            $volumeM3 = $this->volumeEstimator->volumeM3($extraction->getExtractionStartTime(), $extraction->getChunkArrivalTime());
            $value = $this->valuer->value($this->sharesOf($composition), $volumeM3, $unitPrices, $unitVolumes);
            $views[$extraction] = $this->viewOf($value, $volumeM3, $composition, $types);
        }

        return $views;
    }

    /**
     * @param list<CorporationMiningExtraction> $extractions
     *
     * @return array<int, array<int, MoonComposition>> corporationId => moonId => composition
     */
    private function compositionsOf(array $extractions): array
    {
        $compositions = [];
        foreach (array_unique(array_map(static fn (CorporationMiningExtraction $extraction): int => $extraction->getCorporationId(), $extractions)) as $corporationId) {
            foreach ($this->compositionRepository->findBy(['corporationId' => $corporationId]) as $composition) {
                $compositions[$corporationId][$composition->getMoonId()] = $composition;
            }
        }

        return $compositions;
    }

    /**
     * @param array<int, array<int, MoonComposition>> $compositions
     *
     * @return list<int>
     */
    private function typeIdsOf(array $compositions): array
    {
        $typeIds = [];
        foreach ($compositions as $compositionsOfCorporation) {
            foreach ($compositionsOfCorporation as $composition) {
                foreach ($composition->getLines() as $line) {
                    $typeIds[$line['typeId']] = $line['typeId'];
                }
            }
        }

        return array_values($typeIds);
    }

    /**
     * @return array<int, float>
     */
    private function sharesOf(?MoonComposition $composition): array
    {
        $shares = [];
        foreach ($composition?->getLines() ?? [] as $line) {
            $shares[$line['typeId']] = $line['share'];
        }

        return $shares;
    }

    /**
     * @param array<int, InvType> $types
     *
     * @return array<string, mixed>
     */
    private function viewOf(MoonChunkValue $value, float $volumeM3, ?MoonComposition $composition, array $types): array
    {
        return [
            'status' => $value->status,
            'reason' => $value->reason,
            'volumeM3' => $volumeM3,
            'totalValue' => $value->totalValue,
            'perParticipant' => $value->perParticipant,
            'lines' => $value->lines === null ? null : array_map(
                static fn (array $line): array => [...$line, 'typeName' => $types[$line['typeId']]->getTypeName()],
                $value->lines,
            ),
            'scanRecordedAt' => $composition?->getRecordedAt()->setTimezone(new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM),
        ];
    }
}
