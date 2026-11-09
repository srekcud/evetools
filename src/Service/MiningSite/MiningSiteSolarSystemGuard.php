<?php

declare(strict_types=1);

namespace App\Service\MiningSite;

use ApiPlatform\Validator\Exception\ValidationException;
use App\Repository\Sde\MapSolarSystemRepository;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;

final readonly class MiningSiteSolarSystemGuard
{
    private const string UNKNOWN_SOLAR_SYSTEM_MESSAGE = 'Unknown solar system';

    public function __construct(private MapSolarSystemRepository $solarSystemRepository)
    {
    }

    public function assertKnown(?int $solarSystemId): void
    {
        if ($solarSystemId === null || $this->solarSystemRepository->findBySolarSystemId($solarSystemId) !== null) {
            return;
        }

        throw new ValidationException(new ConstraintViolationList([
            new ConstraintViolation(self::UNKNOWN_SOLAR_SYSTEM_MESSAGE, self::UNKNOWN_SOLAR_SYSTEM_MESSAGE, [], $solarSystemId, 'solarSystemId', $solarSystemId),
        ]));
    }
}
