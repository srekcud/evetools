<?php

declare(strict_types=1);

namespace App\Service\Industry\Project\StructureAccess;

final readonly class StructureAccessRefreshReport
{
    public function __construct(
        public int $probed,
        public int $skipped,
        public bool $stoppedForBudget,
        public bool $capped,
    ) {
    }

    /**
     * @return array{probed: int, skipped: int, stoppedForBudget: bool, capped: bool}
     */
    public function toArray(): array
    {
        return [
            'probed' => $this->probed,
            'skipped' => $this->skipped,
            'stoppedForBudget' => $this->stoppedForBudget,
            'capped' => $this->capped,
        ];
    }
}
