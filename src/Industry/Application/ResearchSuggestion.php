<?php

declare(strict_types=1);

namespace App\Industry\Application;

use App\Industry\Domain\Isk;
use App\Industry\Domain\MissingData;

final readonly class ResearchSuggestion
{
    /**
     * @param list<MissingData> $missingData why the saving or the cost is unknown
     */
    public function __construct(
        public int $productTypeId,
        public int $currentLevel,
        public int $targetLevel,
        public int $runs,
        public ?Isk $materialSaving,
        public ?Isk $researchCost,
        public ?float $net,
        public ResearchVerdict $verdict,
        public array $missingData,
        public ResearchJobEstimate $meResearch,
        public ?ResearchJobEstimate $teResearch,
    ) {
    }
}
