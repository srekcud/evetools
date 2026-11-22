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
        return self::ofList($cost->missingData);
    }

    /**
     * @param list<MissingData> $missingData
     *
     * @return list<array{reason: string, typeId: ?int}>
     */
    public static function ofList(array $missingData): array
    {
        return array_map(
            static fn (MissingData $missing): array => ['reason' => $missing->reason, 'typeId' => $missing->typeId],
            $missingData,
        );
    }
}
