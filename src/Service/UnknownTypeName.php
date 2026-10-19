<?php

declare(strict_types=1);

namespace App\Service;

final class UnknownTypeName
{
    public static function label(int $typeId): string
    {
        return "type {$typeId} (name missing from the SDE)";
    }
}
