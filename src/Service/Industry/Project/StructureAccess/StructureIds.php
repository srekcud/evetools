<?php

declare(strict_types=1);

namespace App\Service\Industry\Project\StructureAccess;

final class StructureIds
{
    public const int FIRST_STRUCTURE_ID = 1_000_000_000_000;

    public static function isStructure(int $locationId): bool
    {
        return $locationId >= self::FIRST_STRUCTURE_ID;
    }
}
