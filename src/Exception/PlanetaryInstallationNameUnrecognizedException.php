<?php

declare(strict_types=1);

namespace App\Exception;

final class PlanetaryInstallationNameUnrecognizedException extends \RuntimeException
{
    public function __construct(int $typeId, string $typeName)
    {
        parent::__construct(sprintf('Planetary installation type %d has an unrecognised name: %s', $typeId, $typeName));
    }
}
