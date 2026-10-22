<?php

declare(strict_types=1);

namespace App\Service\Industry;

use App\Industry\Domain\Cost;
use App\Industry\Domain\MissingData;

final class MissingDataPresenter
{
    /**
     * @return list<array{reason: string, typeId: ?int}>
     */
    public static function of(Cost $cost): array
    {
        return array_map(
            static fn (MissingData $missingData): array => ['reason' => $missingData->reason, 'typeId' => $missingData->typeId],
            $cost->missingData,
        );
    }
}
