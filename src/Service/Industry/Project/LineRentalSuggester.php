<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

use App\Entity\IndustryProjectBomLine;
use App\Entity\User;
use App\Repository\CachedIndustryJobRepository;

class LineRentalSuggester
{
    public function __construct(
        private readonly LineRentalCategoryResolver $categoryResolver,
        private readonly CachedIndustryJobRepository $jobRepository,
    ) {
    }

    public function suggest(User $contributor, IndustryProjectBomLine $line): LineRentalSuggestion
    {
        return new LineRentalSuggestion(null, null, null, null, null, null, null);
    }
}
