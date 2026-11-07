<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

final readonly class PlanningRealSpan
{
    /**
     * @param 'done'|'active' $status
     */
    public function __construct(public \DateTimeImmutable $start, public \DateTimeImmutable $end, public string $status)
    {
    }
}
