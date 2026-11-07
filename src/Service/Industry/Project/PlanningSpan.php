<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

final readonly class PlanningSpan
{
    public function __construct(public \DateTimeImmutable $start, public \DateTimeImmutable $end)
    {
    }
}
