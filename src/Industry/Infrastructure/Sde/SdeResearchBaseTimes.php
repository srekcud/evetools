<?php

declare(strict_types=1);

namespace App\Industry\Infrastructure\Sde;

use App\Industry\Application\ResearchBaseTimes;
use App\Industry\Domain\ActivityKind;
use App\Repository\Sde\IndustryActivityProductRepository;
use App\Repository\Sde\IndustryActivityRepository;

final readonly class SdeResearchBaseTimes implements ResearchBaseTimes
{
    private const int ACTIVITY_MANUFACTURING = 1;
    private const int ACTIVITY_COPYING = 5;
    private const int ACTIVITY_RESEARCH_TIME = 3;
    private const int ACTIVITY_RESEARCH_MATERIAL = 4;

    public function __construct(
        private IndustryActivityProductRepository $activityProductRepository,
        private IndustryActivityRepository $activityRepository,
    ) {
    }

    public function of(int $productTypeId, ActivityKind $activity): ?int
    {
        $researchActivityId = match ($activity) {
            ActivityKind::Copying => self::ACTIVITY_COPYING,
            ActivityKind::ResearchMaterial => self::ACTIVITY_RESEARCH_MATERIAL,
            ActivityKind::ResearchTime => self::ACTIVITY_RESEARCH_TIME,
            default => throw new \InvalidArgumentException(\sprintf('%s has no base time.', $activity->name)),
        };

        $blueprint = $this->activityProductRepository->findBlueprintForProduct($productTypeId, self::ACTIVITY_MANUFACTURING);
        if (null === $blueprint) {
            return null;
        }

        $researchTimes = $this->activityRepository->findByTypeIdsAndActivityIds([$blueprint->getTypeId()], [$researchActivityId]);
        $seconds = ($researchTimes[$blueprint->getTypeId().'-'.$researchActivityId] ?? null)?->getTime();

        return null === $seconds || $seconds <= 0 ? null : $seconds;
    }
}
