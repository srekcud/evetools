<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

use App\Entity\IndustryProjectBomLine;
use App\Industry\Application\ConsumingActivities;
use App\Industry\Application\MaterialToSource;
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
    public static function of(array $bomLines, Plan $plan): array
    {
        $consumingActivities = ConsumingActivities::of($plan);
        $materials = [];
        foreach ($bomLines as $line) {
            if ($line->isJob() || $line->getRemainingQuantity() <= 0) {
                continue;
            }
            $materials[] = new MaterialToSource(
                $line->getTypeId(),
                new Quantity($line->getRemainingQuantity()),
                $consumingActivities[$line->getTypeId()] ?? ActivityKind::Manufacturing,
            );
        }

        return $materials;
    }
}
