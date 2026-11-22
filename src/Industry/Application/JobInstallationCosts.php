<?php

declare(strict_types=1);

namespace App\Industry\Application;

use App\Industry\Domain\Cost;
use App\Industry\Domain\Recipe;
use App\Industry\Domain\Runs;

interface JobInstallationCosts
{
    public function installationOf(Recipe $recipe, Runs $runs, ?ProductionStructure $structure): Cost;
}
