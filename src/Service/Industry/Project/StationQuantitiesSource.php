<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

use App\Entity\IndustryProject;

interface StationQuantitiesSource
{
    /**
     * @return list<TypeStationQuantities>
     */
    public function of(IndustryProject $project): array;
}
