<?php

declare(strict_types=1);

namespace App\Service\Planetary\Layout;

final readonly class PlanetaryColonyLayoutName
{
    public const int MAX_LENGTH = 100;

    public function errorCodeOf(string $trimmedName): ?string
    {
        if ($trimmedName === '') {
            return 'name_blank';
        }
        if (mb_strlen($trimmedName) > self::MAX_LENGTH) {
            return 'name_too_long';
        }

        return null;
    }
}
