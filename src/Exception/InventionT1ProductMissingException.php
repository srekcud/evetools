<?php

declare(strict_types=1);

namespace App\Exception;

final class InventionT1ProductMissingException extends \RuntimeException
{
    public function __construct(int $t1BlueprintTypeId)
    {
        parent::__construct(\sprintf(
            'Manufacturing product missing from the SDE for T1 blueprint %d',
            $t1BlueprintTypeId,
        ));
    }
}
