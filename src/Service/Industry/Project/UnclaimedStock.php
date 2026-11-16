<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

final readonly class UnclaimedStock
{
    public static function quantityOf(?int $containerQuantity, int $requiredQuantity, int $claimedQuantity): ?int
    {
        if (null === $containerQuantity) {
            return null;
        }

        return max(0, min($containerQuantity, $requiredQuantity) - $claimedQuantity);
    }

    public static function valueOf(?int $unclaimedQuantity, ?float $launchPrice): ?float
    {
        if (null === $unclaimedQuantity || null === $launchPrice) {
            return null;
        }

        return $unclaimedQuantity * $launchPrice;
    }
}
