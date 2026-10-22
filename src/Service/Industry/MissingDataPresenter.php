<?php

declare(strict_types=1);

namespace App\Service\Industry;

use App\Industry\Domain\Cost;

final class MissingDataPresenter
{
    /**
     * @return list<array{reason: string, typeId: ?int}>
     */
    public static function of(Cost $cost): array
    {
        return [];
    }
}
