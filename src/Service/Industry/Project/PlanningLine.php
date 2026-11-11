<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

use App\Industry\Domain\Multiplier;

final readonly class PlanningLine
{
    /**
     * @param 'manufacturing'|'reaction' $activity
     * @param list<int>                  $scienceSkillIds skills the producer blueprint requires for the activity, Industry and Advanced Industry excluded
     * @param list<PlanningLinkedJob>    $linkedJobs      linked ESI jobs, cancelled and reverted ones included
     */
    public function __construct(
        public string $lineId,
        public int $typeId,
        public string $typeName,
        public string $activity,
        public int $runs,
        public ?int $teLevel,
        public ?int $parentTypeId,
        public ?int $baseTimeSeconds,
        public array $scienceSkillIds,
        public array $linkedJobs,
        public Multiplier $structureTimeModifier = new Multiplier(1.0),
        public bool $structureTimeKnown = true,
    ) {
    }
}
