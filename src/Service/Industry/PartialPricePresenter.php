<?php

declare(strict_types=1);

namespace App\Service\Industry;

use App\Industry\Domain\Cost;

final class PartialPricePresenter
{
    /**
     * @return list<array{reason: string, typeId: int, coveragePercent: float}>
     */
    public static function of(Cost $cost): array
    {
        return [];
    }

    public static function coveragePercentOf(Cost $cost, int $typeId): ?float
    {
        return null;
    }
}
