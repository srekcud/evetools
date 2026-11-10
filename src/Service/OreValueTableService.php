<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Sde\InvType;
use App\Entity\Sde\InvTypeMaterial;
use App\Enum\MarketSide;
use App\Enum\MiningValuationForm;
use App\Enum\MiningValuationMarket;
use App\Repository\Sde\InvTypeMaterialRepository;
use App\Repository\Sde\InvTypeRepository;

final readonly class OreValueTableService
{
    public const string MARKET_JITA = 'jita';
    public const string MARKET_STRUCTURE = 'structure';

    private const int ICE_GROUP_ID = 465;
    private const int HARVESTABLE_CLOUD_GROUP_ID = 711;
    private const float UNUSED_REPROCESS_YIELD = 1.0;
    private const array COMPRESSED_NAME_PREFIXES = ['Compressed ', 'Batch Compressed '];
    private const array BEST_OPTION_TIE_ORDER = ['compressed', 'reprocessed', 'raw'];

    public function __construct(
        private OreValueService $oreValueService,
        private InvTypeRepository $invTypeRepository,
        private InvTypeMaterialRepository $invTypeMaterialRepository,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function build(string $market, MarketSide $side, ?int $structureId, ?float $reprocessYield, float $exportTax = 0.0): array
    {
        $onStructure = $market === self::MARKET_STRUCTURE;
        $oreTypes = array_values(array_filter(
            $this->invTypeRepository->findMineableCandidates(),
            fn (InvType $type): bool => !$this->isCompressedVariant($type->getTypeName()),
        ));
        $typeIds = array_map(static fn (InvType $type): int => $type->getTypeId(), $oreTypes);

        $prices = $this->oreValueService->getBatchOrePrices(
            $typeIds,
            $onStructure ? $structureId : null,
            $reprocessYield ?? self::UNUSED_REPROCESS_YIELD,
            $exportTax,
            $side,
        );
        $materials = $this->invTypeMaterialRepository->findByTypeIds($typeIds);
        $priceMarket = $onStructure ? MiningValuationMarket::Structure : MiningValuationMarket::Jita;

        $rows = array_map(
            fn (InvType $type): array => $this->rowOf(
                $type,
                $prices[$type->getTypeId()],
                array_values($materials[$type->getTypeId()] ?? []),
                $priceMarket,
                $reprocessYield !== null,
            ),
            $oreTypes,
        );

        return $this->sortedWithGaps($rows);
    }

    private function isCompressedVariant(string $typeName): bool
    {
        foreach (self::COMPRESSED_NAME_PREFIXES as $prefix) {
            if (str_starts_with($typeName, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $prices
     * @param list<InvTypeMaterial> $materials
     * @return array<string, mixed>
     */
    private function rowOf(InvType $type, array $prices, array $materials, MiningValuationMarket $priceMarket, bool $reprocessYieldKnown): array
    {
        $volume = $type->getVolume();
        $perCubicMetre = static fn (?float $unitValue): ?float => $unitValue === null || $volume === null || $volume <= 0.0
            ? null
            : $unitValue / $volume;

        $iskPerM3 = [
            'raw' => $perCubicMetre($prices[MiningValuationForm::Raw->priceKeyOn($priceMarket)]),
            'compressed' => $perCubicMetre($prices[MiningValuationForm::Compressed->priceKeyOn($priceMarket)]),
            'reprocessed' => $reprocessYieldKnown ? $perCubicMetre($prices[MiningValuationForm::Reprocessed->priceKeyOn($priceMarket)]) : null,
        ];
        $bestOption = $this->bestOptionOf($iskPerM3);

        return [
            'typeId' => $type->getTypeId(),
            'typeName' => $type->getTypeName(),
            'kind' => $this->kindOf($type->getGroup()->getGroupId()),
            'groupName' => $type->getGroup()->getGroupName(),
            'volume' => $volume,
            'rawIskPerM3' => $iskPerM3['raw'],
            'compressedIskPerM3' => $iskPerM3['compressed'],
            'reprocessedIskPerM3' => $iskPerM3['reprocessed'],
            'bestOption' => $bestOption,
            'bestIskPerM3' => $bestOption === null ? null : $iskPerM3[$bestOption],
            'gapToBestPercent' => null,
            'reprocessable' => $materials !== [],
            'minerals' => $this->mineralsOf($type, $materials),
        ];
    }

    private function kindOf(int $groupId): string
    {
        return match ($groupId) {
            self::ICE_GROUP_ID => 'ice',
            self::HARVESTABLE_CLOUD_GROUP_ID => 'gas',
            default => 'ore',
        };
    }

    /**
     * @param array<string, float|null> $iskPerM3
     */
    private function bestOptionOf(array $iskPerM3): ?string
    {
        $bestOption = null;
        foreach (self::BEST_OPTION_TIE_ORDER as $option) {
            if ($iskPerM3[$option] !== null && ($bestOption === null || $iskPerM3[$option] > $iskPerM3[$bestOption])) {
                $bestOption = $option;
            }
        }

        return $bestOption;
    }

    /**
     * @param list<InvTypeMaterial> $materials
     * @return list<array{typeId: int, typeName: string|null, quantityPerUnit: float|null}>
     */
    private function mineralsOf(InvType $type, array $materials): array
    {
        $portionSize = $type->getPortionSize();

        return array_map(
            fn (InvTypeMaterial $material): array => [
                'typeId' => $material->getMaterialTypeId(),
                'typeName' => $this->invTypeRepository->find($material->getMaterialTypeId())?->getTypeName(),
                'quantityPerUnit' => $portionSize === null || $portionSize <= 0 ? null : $material->getQuantity() / $portionSize,
            ],
            $materials,
        );
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function sortedWithGaps(array $rows): array
    {
        $knownBests = array_filter(array_column($rows, 'bestIskPerM3'), static fn (?float $best): bool => $best !== null);
        $tableBest = $knownBests === [] ? null : max($knownBests);

        $rows = array_map(
            static function (array $row) use ($tableBest): array {
                $row['gapToBestPercent'] = $row['bestIskPerM3'] === null || $tableBest === null || $tableBest <= 0.0
                    ? null
                    : ($tableBest - $row['bestIskPerM3']) / $tableBest * 100;

                return $row;
            },
            $rows,
        );

        usort($rows, static function (array $a, array $b): int {
            if ($a['bestIskPerM3'] === null || $b['bestIskPerM3'] === null) {
                return ($a['bestIskPerM3'] === null) <=> ($b['bestIskPerM3'] === null) ?: $a['typeName'] <=> $b['typeName'];
            }

            return $b['bestIskPerM3'] <=> $a['bestIskPerM3'] ?: $a['typeName'] <=> $b['typeName'];
        });

        return $rows;
    }
}
