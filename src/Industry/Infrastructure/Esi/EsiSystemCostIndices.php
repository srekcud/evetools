<?php

declare(strict_types=1);

namespace App\Industry\Infrastructure\Esi;

use App\Industry\Application\SystemCostIndices;
use App\Industry\Domain\ActivityKind;
use App\Service\Industry\EsiCostIndexService;

final readonly class EsiSystemCostIndices implements SystemCostIndices
{
    public function __construct(private EsiCostIndexService $esiCostIndexService)
    {
    }

    public function costIndexOf(int $solarSystemId, ActivityKind $activity): ?float
    {
        return $this->esiCostIndexService->getCostIndex($solarSystemId, self::esiActivityOf($activity));
    }

    private static function esiActivityOf(ActivityKind $activity): string
    {
        return match ($activity) {
            ActivityKind::Manufacturing => 'manufacturing',
            ActivityKind::Reaction => 'reaction',
            ActivityKind::Copying => 'copying',
            ActivityKind::Invention => 'invention',
        };
    }
}
