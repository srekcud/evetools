<?php

declare(strict_types=1);

namespace App\Service\MiningOperation;

use App\Entity\MiningOperation;
use App\Entity\MiningOperationRecurrence;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;

#[Autoconfigure(public: true)]
final readonly class MiningOperationOccurrenceCreator
{
    /**
     * @return list<MiningOperation>
     */
    public function createDueOccurrences(MiningOperationRecurrence $recurrence, \DateTimeImmutable $now): array
    {
        return [];
    }
}
