<?php

declare(strict_types=1);

namespace App\Service\MiningOperation;

use App\Entity\MiningOperation;
use App\Entity\User;

final readonly class MiningOperationArchive
{
    public function __construct(
        private MiningShareCalculator $shareCalculator,
    ) {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function summaryOf(MiningOperation $operation, User $viewer): ?array
    {
        return null;
    }
}
