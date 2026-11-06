<?php

declare(strict_types=1);

namespace App\Tests\Unit\Industry\Application;

use App\Industry\Application\CostMode;
use App\Industry\Application\FavoriteSystems;
use App\Industry\Application\ProductionCostRequest;
use App\Industry\Application\StructureSelectionPolicy;
use App\Industry\Domain\BlueprintEfficiency;
use App\Industry\Domain\MaterialEfficiency;
use App\Industry\Domain\Runs;
use App\Industry\Domain\TimeEfficiency;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(StructureSelectionPolicy::class)]
#[CoversClass(ProductionCostRequest::class)]
final class StructureSelectionPolicyDefaultsTest extends TestCase
{
    public function testFavoriteSystemOnlyPolicyRefusesStructuresOutsideTheFavoriteSystemWithoutAnyMinimumSaving(): void
    {
        $policy = StructureSelectionPolicy::favoriteSystemOnly();

        $this->assertFalse($policy->acceptsOutsideFavoriteSystem);
        $this->assertSame(0.0, $policy->minimumSaving->amount);
    }

    public function testProductionCostRequestWithoutPolicyKeepsTheFavoriteSystemOnly(): void
    {
        $request = new ProductionCostRequest(
            productTypeId: 12005,
            runs: new Runs(3),
            efficiency: new BlueprintEfficiency(new MaterialEfficiency(2), new TimeEfficiency(4)),
            mode: CostMode::Plan,
            structures: [],
            favoriteSystems: FavoriteSystems::none(),
        );

        $this->assertFalse($request->selectionPolicy->acceptsOutsideFavoriteSystem);
        $this->assertSame(0.0, $request->selectionPolicy->minimumSaving->amount);
    }
}
