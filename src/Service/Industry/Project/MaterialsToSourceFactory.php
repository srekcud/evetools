<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

use App\Entity\IndustryProjectBomLine;
use App\Enum\SourcingMarket;
use App\Industry\Application\ConsumingActivities;
use App\Industry\Application\MaterialToSource;
use App\Industry\Application\SourcingSide;
use App\Industry\Domain\ActivityKind;
use App\Industry\Domain\Plan;
use App\Industry\Domain\Quantity;

final class MaterialsToSourceFactory
{
    /**
     * @param list<IndustryProjectBomLine> $bomLines
     *
     * @return list<MaterialToSource>
     */
    public static function of(array $bomLines, Plan $plan, ?ProjectLineFulfillments $fulfillments = null): array
    {
        $fulfillments ??= ProjectLineFulfillments::none();
        $consumingActivities = ConsumingActivities::of($plan);
        $materials = [];
        foreach ($bomLines as $line) {
            $remainingQuantity = $fulfillments->remainingOf($line);
            if ($line->isJob() || $remainingQuantity <= 0) {
                continue;
            }
            $materials[] = new MaterialToSource(
                $line->getTypeId(),
                new Quantity($remainingQuantity),
                $consumingActivities[$line->getTypeId()] ?? ActivityKind::Manufacturing,
                $line->getId()->toRfc4122(),
                self::sideOf($line),
            );
        }

        return $materials;
    }

    /**
     * @param list<IndustryProjectBomLine> $bomLines
     * @param array<string, int>           $reservedQuantityByLineId
     *
     * @return list<MaterialToSource>
     */
    public static function reservedOf(array $bomLines, Plan $plan, array $reservedQuantityByLineId, ?ProjectLineFulfillments $fulfillments = null): array
    {
        $fulfillments ??= ProjectLineFulfillments::none();
        $consumingActivities = ConsumingActivities::of($plan);
        $materials = [];
        foreach ($bomLines as $line) {
            $reservedQuantity = $reservedQuantityByLineId[$line->getId()->toRfc4122()] ?? 0;
            $quantityToSource = min($reservedQuantity, $fulfillments->remainingOf($line));
            if ($line->isJob() || $quantityToSource <= 0) {
                continue;
            }
            $materials[] = new MaterialToSource(
                $line->getTypeId(),
                new Quantity($quantityToSource),
                $consumingActivities[$line->getTypeId()] ?? ActivityKind::Manufacturing,
                $line->getId()->toRfc4122(),
                self::sideOf($line),
            );
        }

        return $materials;
    }

    private static function sideOf(IndustryProjectBomLine $line): ?SourcingSide
    {
        return match ($line->getSourcingMarket()) {
            SourcingMarket::Jita => SourcingSide::Jita,
            SourcingMarket::Structure => SourcingSide::Structure,
            null => null,
        };
    }
}
