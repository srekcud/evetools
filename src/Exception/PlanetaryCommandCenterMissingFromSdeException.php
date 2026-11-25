<?php

declare(strict_types=1);

namespace App\Exception;

final class PlanetaryCommandCenterMissingFromSdeException extends \RuntimeException
{
    public function __construct(string $planetType)
    {
        parent::__construct(sprintf('No command center found in the SDE for the %s planet type', $planetType));
    }
}
