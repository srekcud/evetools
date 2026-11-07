<?php

declare(strict_types=1);

namespace App\Service\Industry\Project\Courier;

final class CourierQuoteCalculator
{
    public const int DAYS_TO_COMPLETE = 3;
    public const int EXPIRATION_DAYS = 7;

    /**
     * @param list<array{typeId: int, quantity: int, totalPrice: float}> $purchases
     * @param array<int, float|null>                                      $volumeByTypeId
     */
    public function quote(array $purchases, array $volumeByTypeId, bool $hasWorkshopSystem, ?int $jumps): CourierQuote
    {
        return new CourierQuote(null, null, null, null, 'unimplemented', []);
    }
}
