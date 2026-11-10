<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

final readonly class ActionAssessment
{
    public function __construct(
        public ActionSeverity $severity,
        public ?float $impactIsk,
    ) {
    }
}
