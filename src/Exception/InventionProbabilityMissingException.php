<?php

declare(strict_types=1);

namespace App\Exception;

final class InventionProbabilityMissingException extends \RuntimeException
{
    public function __construct(int $t2ProductTypeId, int $t1BlueprintTypeId)
    {
        parent::__construct(\sprintf(
            'Invention probability missing from the SDE for T2 product %d invented from T1 blueprint %d',
            $t2ProductTypeId,
            $t1BlueprintTypeId,
        ));
    }
}
