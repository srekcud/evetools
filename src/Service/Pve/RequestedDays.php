<?php

declare(strict_types=1);

namespace App\Service\Pve;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

final class RequestedDays
{
    public const int DEFAULT = 30;
    public const int MINIMUM = 1;
    public const int MAXIMUM = 3650;

    private function __construct()
    {
    }

    public static function fromRequest(?Request $request): int
    {
        $requestedDays = $request?->query->get('days', self::DEFAULT) ?? self::DEFAULT;

        $days = filter_var($requestedDays, FILTER_VALIDATE_INT, ['options' => ['min_range' => self::MINIMUM, 'max_range' => self::MAXIMUM]]);

        if ($days === false) {
            throw new BadRequestHttpException(sprintf('days must be an integer between %d and %d', self::MINIMUM, self::MAXIMUM));
        }

        return $days;
    }
}
