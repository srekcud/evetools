<?php

declare(strict_types=1);

namespace App\Exception;

final class StepMaterialsMissingFromSdeException extends \RuntimeException
{
    public function __construct(string $blueprintKey)
    {
        parent::__construct(\sprintf('Materials of blueprint %s missing from the SDE', $blueprintKey));
    }
}
