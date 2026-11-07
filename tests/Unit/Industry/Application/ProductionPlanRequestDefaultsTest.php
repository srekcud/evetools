<?php

declare(strict_types=1);

namespace App\Tests\Unit\Industry\Application;

use App\Industry\Application\FavoriteSystems;
use App\Industry\Application\ProductionPlanRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProductionPlanRequest::class)]
final class ProductionPlanRequestDefaultsTest extends TestCase
{
    public function testProductionPlanRequestWithoutPolicyKeepsTheFavoriteSystemOnlyWithoutAnyMinimumSaving(): void
    {
        $request = new ProductionPlanRequest([], [], FavoriteSystems::none());

        self::assertSame(
            [false, 0.0],
            [$request->selectionPolicy->acceptsOutsideFavoriteSystem, $request->selectionPolicy->minimumSaving->amount],
        );
    }
}
