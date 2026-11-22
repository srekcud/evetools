<?php

declare(strict_types=1);

namespace App\Tests\Unit\Industry\Application\Double;

use App\Industry\Application\JobInstallationCosts;
use App\Industry\Application\ProductionStructure;
use App\Industry\Domain\Cost;
use App\Industry\Domain\MissingData;
use App\Industry\Domain\Recipe;
use App\Industry\Domain\Runs;

final class FixedJobInstallationCosts implements JobInstallationCosts
{
    public array $calls = [];

    public function __construct(private readonly array $costsByProductTypeId = [])
    {
    }

    public function installationOf(Recipe $recipe, Runs $runs, ?ProductionStructure $structure): Cost
    {
        $this->calls[] = ['productTypeId' => $recipe->productTypeId, 'runs' => $runs->value, 'structureId' => $structure?->id];

        return $this->costsByProductTypeId[$recipe->productTypeId] ?? Cost::unknown(MissingData::costIndex());
    }
}
