<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectParticipant;

final readonly class LineReservationScope
{
    public function __construct(
        public IndustryProject $project,
        public IndustryProjectBomLine $line,
        public IndustryProjectParticipant $participant,
    ) {
    }
}
