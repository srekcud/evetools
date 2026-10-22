<?php

declare(strict_types=1);

namespace App\Service\GroupIndustry;

use App\Entity\User;

final class PilotName
{
    private const string WITHOUT_MAIN_CHARACTER = 'Unknown';

    public static function of(?User $user): ?string
    {
        if ($user === null) {
            return null;
        }

        return $user->getMainCharacter()?->getName() ?? self::WITHOUT_MAIN_CHARACTER;
    }
}
