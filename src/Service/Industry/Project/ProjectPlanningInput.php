<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

final readonly class ProjectPlanningInput
{
    /**
     * @param list<PlanningLine>      $lines      manufacturing and reaction job lines of the BOM
     * @param list<PlanningMaterial>  $materials  material lines of the BOM
     * @param list<PlanningCharacter> $characters characters of the project owner
     */
    public function __construct(
        public \DateTimeImmutable $anchor,
        public array $lines,
        public array $materials,
        public array $characters,
    ) {
    }
}
