<?php

declare(strict_types=1);

namespace App\Tests\Unit\Industry\Application;

use App\Industry\Application\WorkStructureAssignments;
use App\Industry\Application\WorkTypeClassifier;
use App\Industry\Domain\ActivityKind;
use App\Industry\Domain\OutputPerRun;
use App\Industry\Domain\Recipe;
use App\Industry\Domain\Runs;
use App\Tests\Unit\Industry\Application\Double\InMemoryShipProducts;
use App\Tests\Unit\Industry\Application\Double\StructureTotalCostExample as Example;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(WorkStructureAssignments::class)]
final class WorkStructureAssignmentsTest extends TestCase
{
    private const int HULL = 12006;
    private const int FINAL_PRODUCT = 12005;
    private const int COMPONENT = 11478;
    private const int REACTION_PRODUCT = 16670;

    public function testStructureOfTheWorkTypeOfTheRecipeIsReturned(): void
    {
        $assignments = $this->assignments();

        $this->assertSame('C', $assignments->structureFor($this->recipeOf(self::FINAL_PRODUCT, ActivityKind::Manufacturing))?->id);
        $this->assertSame('D', $assignments->structureFor($this->recipeOf(self::HULL, ActivityKind::Manufacturing))?->id);
    }

    public function testWorkTypeWithoutAssignedStructureHasNoStructure(): void
    {
        $assignments = $this->assignments();

        $this->assertNull($assignments->structureFor($this->recipeOf(self::COMPONENT, ActivityKind::Manufacturing)));
        $this->assertNull($assignments->structureFor($this->recipeOf(self::REACTION_PRODUCT, ActivityKind::Reaction)));
    }

    private function assignments(): WorkStructureAssignments
    {
        return new WorkStructureAssignments(
            new WorkTypeClassifier(new InMemoryShipProducts([self::HULL])),
            ['final_products' => Example::structureC(), 'hulls' => Example::structureD()],
            [self::FINAL_PRODUCT],
        );
    }

    private function recipeOf(int $productTypeId, ActivityKind $activity): Recipe
    {
        return new Recipe($productTypeId, $activity, new OutputPerRun(1), [], new Runs(300), 3600);
    }
}
