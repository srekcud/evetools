<?php

declare(strict_types=1);

namespace App\Tests\Unit\Industry\Application;

use App\Industry\Application\FavoriteSystems;
use App\Industry\Application\StructureChoiceStatus;
use App\Industry\Application\StructureMaterialModifiers;
use App\Industry\Application\StructureSelectionPolicy;
use App\Industry\Application\StructureSelector;
use App\Industry\Domain\Isk;
use App\Industry\Domain\Runs;
use App\Tests\Unit\Industry\Application\Double\InMemoryRigCategories;
use App\Tests\Unit\Industry\Application\Double\StructureTotalCostExample as Example;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(StructureMaterialModifiers::class)]
final class StructureMaterialModifiersTotalCostTest extends TestCase
{
    private const float MINIMUM_SAVING = 100_000.0;

    public function testSavingOnTheTenReferenceRunsExceedsTheMinimumSavingAndChoosesTheCheaperStructure(): void
    {
        $choice = self::modifiersOver(new Runs(Example::REFERENCE_RUNS))->choiceFor(Example::recipe());

        $this->assertSame('C', $choice->structure?->id);
        $this->assertSame(StructureChoiceStatus::BestByTotalCost, $choice->status);
    }

    public function testSavingOnASingleReferenceRunStaysBelowTheMinimumSaving(): void
    {
        $choice = self::modifiersOver(new Runs(1))->choiceFor(Example::recipe());

        $this->assertSame('A', $choice->structure?->id);
        $this->assertSame(StructureChoiceStatus::BestInFavoriteSystem, $choice->status);
    }

    private static function modifiersOver(Runs $referenceRuns): StructureMaterialModifiers
    {
        $selector = new StructureSelector(
            [Example::workshopStructureWithNeutralRig(), Example::structureC()],
            new FavoriteSystems(Example::WORKSHOP_SYSTEM_ID, null),
            Example::costIndices(),
            Example::comparator(),
            new StructureSelectionPolicy(true, new Isk(self::MINIMUM_SAVING)),
        );

        return new StructureMaterialModifiers(
            $selector,
            new InMemoryRigCategories(manufacturing: [Example::PRODUCT_TYPE_ID => Example::PRODUCT_CATEGORY]),
            [],
            $referenceRuns,
        );
    }
}
