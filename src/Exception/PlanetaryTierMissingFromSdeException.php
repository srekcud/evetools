<?php

declare(strict_types=1);

namespace App\Exception;

final class PlanetaryTierMissingFromSdeException extends \RuntimeException
{
    public function __construct(int $typeId)
    {
        parent::__construct(sprintf('No PI tier found in the SDE market groups for type %d', $typeId));
    }
}
