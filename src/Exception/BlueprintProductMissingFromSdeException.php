<?php

declare(strict_types=1);

namespace App\Exception;

final class BlueprintProductMissingFromSdeException extends \RuntimeException
{
    public function __construct(int $blueprintTypeId)
    {
        parent::__construct(\sprintf('Product of blueprint %d missing from the SDE', $blueprintTypeId));
    }
}
