<?php

declare(strict_types=1);

namespace App\Tests\Unit\Industry\Application\Double;

use App\Industry\Application\ResearchBaseTimes;
use App\Industry\Domain\ActivityKind;

final readonly class InMemoryResearchBaseTimes implements ResearchBaseTimes
{
    public function __construct(private array $secondsByProductTypeIdAndActivity)
    {
    }

    public function of(int $productTypeId, ActivityKind $activity): ?int
    {
        return $this->secondsByProductTypeIdAndActivity[$productTypeId][$activity->name] ?? null;
    }
}
