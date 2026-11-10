<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\AlertDirection;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

final class MarketAlertRules
{
    public static function directionOrBadRequest(string $direction): AlertDirection
    {
        return AlertDirection::tryFrom($direction)
            ?? throw new BadRequestHttpException('Invalid direction. Must be "above" or "below"');
    }

    public static function assertThresholdIsPositive(float $threshold): void
    {
        if ($threshold <= 0) {
            throw new BadRequestHttpException('Threshold must be positive');
        }
    }
}
