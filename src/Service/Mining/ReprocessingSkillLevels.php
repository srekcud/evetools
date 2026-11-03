<?php

declare(strict_types=1);

namespace App\Service\Mining;

final readonly class ReprocessingSkillLevels
{
    public function __construct(
        public int $reprocessing,
        public int $reprocessingEfficiency,
    ) {
    }

    public function oreProcessing(int $oreProcessingSkillTypeId): int
    {
        return -1;
    }
}
