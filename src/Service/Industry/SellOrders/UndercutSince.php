<?php

declare(strict_types=1);

namespace App\Service\Industry\SellOrders;

use App\Enum\OrderPositionState;

final readonly class UndercutSince
{
    public static function resolve(?\DateTimeImmutable $previous, OrderPositionState $state, \DateTimeImmutable $now): ?\DateTimeImmutable
    {
        if ($state !== OrderPositionState::Undercut) {
            return null;
        }

        return $previous ?? $now;
    }
}
