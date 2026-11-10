<?php

declare(strict_types=1);

namespace App\Service\MiningOperation;

use App\Entity\MiningOperation;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;

#[Autoconfigure(public: true)]
final readonly class MiningHaulerPlanReader
{
    public function read(MiningOperation $operation): MiningHaulerPlanReport
    {
        return new MiningHaulerPlanReport(PersistedUuid::of($operation->getId()));
    }
}
