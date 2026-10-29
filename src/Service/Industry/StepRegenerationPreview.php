<?php

declare(strict_types=1);

namespace App\Service\Industry;

final readonly class StepRegenerationPreview
{
    /**
     * @param list<StepRegenerationPreviewLine> $lines
     */
    public function __construct(
        public array $lines,
        public int $stepCountAfter,
    ) {
    }
}
