<?php

declare(strict_types=1);

namespace App\Exception;

final class PlanetaryInstallationAttributeMissingException extends \RuntimeException
{
    public function __construct(int $typeId, string $attribute)
    {
        parent::__construct(sprintf('Attribute %s of planetary installation type %d is missing from the SDE', $attribute, $typeId));
    }
}
