<?php

declare(strict_types=1);

namespace App\Service\Mining;

use App\Entity\MiningOperationMember;
use App\Entity\Sde\InvType;
use App\Entity\User;
use App\Enum\MinedQuantityState;
use App\Repository\ReprocessingProfileRepository;
use App\Repository\Sde\InvTypeMaterialRepository;
use App\Repository\Sde\InvTypeRepository;
use App\Service\Industry\StockpileService;
use App\Service\MiningOperation\MinedQuantityCalculator;
use App\Service\MiningOperation\PersistedUuid;

final readonly class OreStockpileCoverageService
{
    private const string PORTION_SIZE_UNKNOWN = 'portion_size_unknown';
    private const float FULL_COVERAGE_PERCENT = 100.0;
    private const int COVERAGE_PERCENT_PRECISION = 2;
    private const int NO_DEFICIT = 0;

    public function __construct(
        private MinedQuantityCalculator $calculator,
        private ReprocessingProfileRepository $profileRepository,
        private InvTypeMaterialRepository $materialRepository,
        private InvTypeRepository $typeRepository,
        private StockpileService $stockpileService,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function coverageOf(User $user, MiningOperationMember $member): array
    {
        $quantity = $this->calculator->ofParticipant($member);
        $reprocessYield = $this->profileRepository->findOneBy(['user' => $user])?->getReprocessYield();

        return [
            'operationId' => PersistedUuid::of($member->getOperation()->getId()),
            'state' => $quantity->state->value,
            'unknownReason' => $quantity->unknownReason?->value,
            'yieldKnown' => $reprocessYield !== null,
            'reprocessYield' => $reprocessYield,
            'minerals' => $quantity->state === MinedQuantityState::Known
                ? $this->mineralsOf($user, $quantity->quantitiesByTypeId, $reprocessYield)
                : [],
        ];
    }

    /**
     * @param array<int, int> $minedQuantitiesByTypeId
     *
     * @return list<array<string, mixed>>
     */
    private function mineralsOf(User $user, array $minedQuantitiesByTypeId, ?float $reprocessYield): array
    {
        $materialsByOreTypeId = $this->materialRepository->findByTypeIds(
            array_keys(array_filter($minedQuantitiesByTypeId, static fn (int $quantity): bool => $quantity > 0)),
        );
        if ($materialsByOreTypeId === []) {
            return [];
        }

        $mineralTypeIds = [];
        foreach ($materialsByOreTypeId as $materials) {
            foreach ($materials as $material) {
                $mineralTypeIds[] = $material->getMaterialTypeId();
            }
        }
        $types = $this->typeRepository->findByTypeIds(array_values(array_unique([...array_keys($materialsByOreTypeId), ...$mineralTypeIds])));

        $producedByMineralTypeId = [];
        $mineralsWithoutPortionSize = [];
        foreach ($materialsByOreTypeId as $oreTypeId => $materials) {
            $portionSize = isset($types[$oreTypeId]) ? $types[$oreTypeId]->getPortionSize() : null;
            foreach ($materials as $material) {
                $mineralTypeId = $material->getMaterialTypeId();
                $producedByMineralTypeId[$mineralTypeId] ??= 0.0;
                if ($portionSize === null || $portionSize <= 0) {
                    $mineralsWithoutPortionSize[$mineralTypeId] = true;
                    continue;
                }
                if ($reprocessYield !== null) {
                    $producedByMineralTypeId[$mineralTypeId] += $minedQuantitiesByTypeId[$oreTypeId] * $material->getQuantity() * $reprocessYield / $portionSize;
                }
            }
        }
        ksort($producedByMineralTypeId);

        $deficitsByTypeId = $this->deficitsOf($user);

        $minerals = [];
        foreach ($producedByMineralTypeId as $mineralTypeId => $produced) {
            $minerals[] = $this->mineralOf(
                $mineralTypeId,
                $types[$mineralTypeId] ?? null,
                $reprocessYield === null || isset($mineralsWithoutPortionSize[$mineralTypeId]) ? null : $produced,
                array_key_exists($mineralTypeId, $deficitsByTypeId) ? $deficitsByTypeId[$mineralTypeId] : self::NO_DEFICIT,
                $reprocessYield !== null && isset($mineralsWithoutPortionSize[$mineralTypeId]),
            );
        }

        return $minerals;
    }

    /**
     * @return array<int, int>
     */
    private function deficitsOf(User $user): array
    {
        $deficitsByTypeId = [];
        foreach ($this->stockpileService->getStockpileStatus($user)['shoppingList'] as $line) {
            $deficitsByTypeId[$line['typeId']] = $line['deficit'];
        }

        return $deficitsByTypeId;
    }

    /**
     * @return array<string, mixed>
     */
    private function mineralOf(int $typeId, ?InvType $type, ?float $produced, int $deficit, bool $portionSizeUnknown): array
    {
        $hasDeficit = $deficit > 0;

        return [
            'typeId' => $typeId,
            'typeName' => $type === null ? sprintf('Type %d', $typeId) : $type->getTypeName(),
            'producedQuantity' => $produced,
            'deficit' => $deficit,
            'hasDeficit' => $hasDeficit,
            'coveragePercent' => $produced !== null && $hasDeficit
                ? round(min(self::FULL_COVERAGE_PERCENT, $produced / $deficit * 100), self::COVERAGE_PERCENT_PRECISION)
                : null,
            'coversWholeDeficit' => $produced === null ? null : $hasDeficit && $produced >= $deficit,
            'coverageUnknownReason' => $portionSizeUnknown ? self::PORTION_SIZE_UNKNOWN : null,
        ];
    }
}
