<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

final readonly class PlanningLeftOutLine
{
    public function __construct(public string $lineId, public string $typeName)
    {
    }
}
