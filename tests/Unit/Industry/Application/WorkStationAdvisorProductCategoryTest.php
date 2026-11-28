<?php

declare(strict_types=1);

namespace App\Tests\Unit\Industry\Application;

use App\Industry\Application\FavoriteSystems;
use App\Industry\Application\PlannedProduction;
use App\Industry\Application\ProductionStructure;
use App\Industry\Application\WorkStationAdvisor;
use App\Industry\Application\WorkStationRow;
use App\Industry\Application\WorkType;
use App\Industry\Application\WorkTypeAdvice;
use App\Industry\Application\WorkTypeClassifier;
use App\Industry\Domain\ActivityKind;
use App\Tests\Unit\Industry\Application\Double\InMemoryRigCategories;
use App\Tests\Unit\Industry\Application\Double\InMemoryShipProducts;
use App\Tests\Unit\Industry\Application\Double\StructureTotalCostExample as Example;
use App\Tests\Unit\Industry\Application\Double\WorkStationAdvisorScenario as Scenario;
use App\Tests\Unit\Industry\Domain\Double\InMemoryBlueprintCatalog;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(WorkStationAdvisor::class)]
#[CoversClass(WorkStationRow::class)]
#[Group('issue-1474')]
final class WorkStationAdvisorProductCategoryTest extends TestCase
{
    private const int MATERIAL_QUANTITY = 100_000;

    public function testEveryRowCarriesTheRigCategoryOfTheProductItsMaterialBonusWasComputedFor(): void
    {
        $advice = $this->finalProductsAdvice(new InMemoryRigCategories([Example::PRODUCT_TYPE_ID => Example::PRODUCT_CATEGORY]), [Example::workshopStructureWithoutRig(), Example::structureC()]);

        $this->assertSame([Example::PRODUCT_CATEGORY, Example::PRODUCT_CATEGORY], array_map(static fn (WorkStationRow $row): ?string => $row->productCategory, $advice->rows));
    }

    public function testProductCategoryIsAbsentWhenNoRigCategoryTargetsTheProduct(): void
    {
        $advice = $this->finalProductsAdvice(new InMemoryRigCategories(), [Example::workshopStructureWithoutRig()]);

        $this->assertNull($advice->rows[0]->productCategory);
    }

    public function testRowsOfBlueprintsHaveNoProductCategory(): void
    {
        $advices = $this->advise(new InMemoryRigCategories([Example::PRODUCT_TYPE_ID => Example::PRODUCT_CATEGORY]), [Example::workshopStructureWithoutRig()], true);

        $blueprints = array_values(array_filter($advices, static fn (WorkTypeAdvice $advice): bool => WorkType::Blueprints === $advice->workType));
        $this->assertCount(1, $blueprints);
        $this->assertNotSame([], $blueprints[0]->rows);
        $this->assertSame([null], array_unique(array_map(static fn (WorkStationRow $row): ?string => $row->productCategory, $blueprints[0]->rows), \SORT_REGULAR));
    }

    public function testProductCategorySurvivesTheDeltaRanking(): void
    {
        $row = $this->finalProductsAdvice(new InMemoryRigCategories([Example::PRODUCT_TYPE_ID => Example::PRODUCT_CATEGORY]), [Example::workshopStructureWithoutRig()])->rows[0];

        $this->assertSame(Example::PRODUCT_CATEGORY, $row->withDelta(12.0)->productCategory);
    }

    /**
     * @param list<ProductionStructure> $structures
     */
    private function finalProductsAdvice(InMemoryRigCategories $rigCategories, array $structures): WorkTypeAdvice
    {
        foreach ($this->advise($rigCategories, $structures, false) as $advice) {
            if (WorkType::FinalProducts === $advice->workType) {
                return $advice;
            }
        }

        $this->fail('The plan has no final products work type.');
    }

    /**
     * @param list<ProductionStructure> $structures
     *
     * @return list<WorkTypeAdvice>
     */
    private function advise(InMemoryRigCategories $rigCategories, array $structures, bool $includesBlueprints): array
    {
        $volumes = Example::itemVolumes();
        $advisor = new WorkStationAdvisor(
            new InMemoryBlueprintCatalog(Example::recipe()),
            $rigCategories,
            Example::comparator(),
            Example::costIndices(),
            $volumes,
            new WorkTypeClassifier(new InMemoryShipProducts([Example::PRODUCT_TYPE_ID])),
            Example::jumpDistances(),
        );
        $plan = Scenario::plan(
            [Scenario::job(Example::PRODUCT_TYPE_ID, ActivityKind::Manufacturing, Example::REFERENCE_RUNS, [Example::MATERIAL_TYPE_ID => self::MATERIAL_QUANTITY])],
            [Example::MATERIAL_TYPE_ID => self::MATERIAL_QUANTITY],
        );

        return $advisor->advise(new PlannedProduction($plan, []), $structures, new FavoriteSystems(Example::WORKSHOP_SYSTEM_ID, null, null), [Example::PRODUCT_TYPE_ID], [], $includesBlueprints);
    }
}
