<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

use App\Industry\Application\ProductionStructure;
use App\Industry\Application\StructureRig;
use App\Industry\Application\WorkStationRow;
use App\Industry\Application\WorkTransportLeg;
use App\Industry\Application\WorkTypeAdvice;
use App\Industry\Domain\ActivityKind;
use App\Service\Industry\MissingDataPresenter;

final class WorkStationPresenter
{
    private const int DISPLAYED_DECIMALS = 2;

    private const int DISPLAYED_TAX_DECIMALS = 4;

    private const float PERCENT = 100.0;

    /**
     * @param array<string, array{name: string, kind: string}> $structureLabels by structure id
     * @param array<int, string>                               $solarSystemNames by solar system id
     *
     * @return array<string, mixed>
     */
    public static function of(WorkTypeAdvice $advice, ?ProjectWorkStationLock $lock, ?string $storedRecommendedStructureId, array $structureLabels, array $solarSystemNames): array
    {
        return [
            'workType' => $advice->workType->value,
            'activity' => self::activityNameOf($advice->activity),
            'jobs' => $advice->jobs,
            'runs' => $advice->runs,
            'recommendedStructureId' => $advice->recommendedStructureId,
            'chosenStructureId' => $advice->chosenStructureId,
            'storedRecommendedStructureId' => $storedRecommendedStructureId,
            'choiceStale' => $advice->choiceStale,
            'savingIfRecommended' => self::rounded($advice->savingIfRecommended),
            'locked' => null !== $lock,
            'lockReason' => $lock?->value,
            'costsCompared' => $advice->costsCompared,
            'rows' => array_map(
                static fn (WorkStationRow $row): array => self::rowOf($row, $structureLabels, $solarSystemNames),
                $advice->rows,
            ),
        ];
    }

    /**
     * @param array<string, array{name: string, kind: string}> $structureLabels
     * @param array<int, string>                               $solarSystemNames
     *
     * @return array<string, mixed>
     */
    private static function rowOf(WorkStationRow $row, array $structureLabels, array $solarSystemNames): array
    {
        $structure = $row->structure;
        $solarSystemId = $structure->solarSystemId;

        return [
            'structureId' => $structure->id,
            'name' => $structureLabels[$structure->id]['name'] ?? null,
            'kind' => $structureLabels[$structure->id]['kind'] ?? null,
            'solarSystemId' => $solarSystemId,
            'solarSystemName' => null === $solarSystemId ? null : ($solarSystemNames[$solarSystemId] ?? null),
            'inFavoriteSystem' => $row->inFavoriteSystem,
            'jumps' => $row->jumps,
            'materialBonusPercent' => round((1.0 - $row->materialMultiplier->value) * self::PERCENT, self::DISPLAYED_DECIMALS),
            'facilityTaxPercent' => null === $structure->facilityTaxRate ? null : round($structure->facilityTaxRate * self::PERCENT, self::DISPLAYED_TAX_DECIMALS),
            'materialsCost' => self::rounded($row->materialsCost),
            'installationCost' => self::rounded($row->installationCost),
            'transportCost' => self::rounded($row->transportCost),
            'transportLegs' => array_map(self::legOf(...), $row->transportLegs),
            'totalCost' => self::rounded($row->totalCost),
            'delta' => self::rounded($row->delta),
            'missingData' => MissingDataPresenter::ofList($row->missingData),
            'productCategory' => $row->productCategory,
            'rigs' => array_map(static fn (StructureRig $rig): array => self::rigOf($rig, $structure), $structure->rigs),
            'noRigForProduct' => null !== $row->productCategory && !self::anyRigTargets($structure, $row->productCategory),
        ];
    }

    /**
     * @return array{categories: list<string>, bonusPercent: ?float}
     */
    private static function rigOf(StructureRig $rig, ProductionStructure $structure): array
    {
        $effectivePercent = $rig->materialBonus->effectivePercentIn($structure->security);

        return [
            'categories' => $rig->targetedCategories,
            'bonusPercent' => null === $effectivePercent ? null : round($effectivePercent, self::DISPLAYED_DECIMALS),
        ];
    }

    private static function anyRigTargets(ProductionStructure $structure, string $productCategory): bool
    {
        foreach ($structure->rigs as $rig) {
            if ($rig->targets($productCategory)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private static function legOf(WorkTransportLeg $leg): array
    {
        return [
            'kind' => $leg->kind->value,
            'fromStructureId' => $leg->fromStructureId,
            'toStructureId' => $leg->toStructureId,
            'cubicMeters' => $leg->cubicMeters,
            'cost' => self::rounded($leg->cost),
        ];
    }

    private static function rounded(?float $amount): ?float
    {
        return null === $amount ? null : round($amount, self::DISPLAYED_DECIMALS);
    }

    private static function activityNameOf(ActivityKind $activity): string
    {
        return match ($activity) {
            ActivityKind::Manufacturing => 'manufacturing',
            ActivityKind::Reaction => 'reaction',
            ActivityKind::Copying => 'copy',
            ActivityKind::Invention, ActivityKind::ResearchMaterial, ActivityKind::ResearchTime => throw new \LogicException(\sprintf('A work type has no %s activity.', $activity->name)),
        };
    }
}
