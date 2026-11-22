<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Industry;

use App\Repository\Sde\InvTypeRepository;
use App\Service\Industry\RigCatalogue;
use App\State\Provider\Industry\RigOptionsProvider;
use App\Tests\Unit\Support\StubsSdeStructureBonuses;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(RigOptionsProvider::class)]
final class RigOptionsProviderTest extends TestCase
{
    use StubsSdeStructureBonuses;

    private const string FROZEN_BONUSES_PATH = '/Fixtures/Golden/rig-bonuses-highsec.json';

    public static function catalogueRigProvider(): iterable
    {
        foreach (RigCatalogue::RIGS_BY_FAMILY as $rigs) {
            foreach ($rigs as $rig) {
                yield $rig['name'] => [$rig];
            }
        }
    }

    #[DataProvider('catalogueRigProvider')]
    public function testTargetCategoriesAreTheFrozenOnesTheEngineAppliesTheRigTo(array $rig): void
    {
        $this->assertArrayHasKey($rig['name'], $this->frozenBonuses());
        $frozenCategories = array_keys($this->frozenBonuses()[$rig['name']]);
        sort($frozenCategories);

        $targetCategories = $rig['targetCategories'];
        sort($targetCategories);
        $this->assertSame($frozenCategories, $targetCategories);
    }

    public function testEveryStructureSizeIsOfferedItsWholeRigSetOfTheCatalogue(): void
    {
        $countBySize = [];
        foreach (RigCatalogue::RIGS_BY_FAMILY as $family => $rigs) {
            foreach ($rigs as $rig) {
                $key = $family.' '.$rig['size'];
                $countBySize[$key] = ($countBySize[$key] ?? 0) + 1;
            }
        }

        $this->assertSame(
            ['manufacturing M' => 66, 'manufacturing L' => 36, 'manufacturing XL' => 9, 'reaction M' => 6, 'reaction L' => 2],
            $countBySize,
        );
    }

    #[Group('issue-1331')]
    public function testCatalogueCarriesNoBonusOfItsOwn(): void
    {
        foreach (RigCatalogue::RIGS_BY_FAMILY as $rigs) {
            foreach ($rigs as $rig) {
                $this->assertArrayNotHasKey('bonus', $rig, $rig['name']);
                $this->assertArrayNotHasKey('timeBonus', $rig, $rig['name']);
                $this->assertArrayNotHasKey('costBonus', $rig, $rig['name']);
            }
        }
    }

    #[Group('issue-1331')]
    public function testMaterialRigStatesItsSdeMaterialBonusAndNoTimeNorCostBonus(): void
    {
        $rig = $this->offeredRigs()[self::SDE_AMMUNITION_ME_II];

        $expected = ['name' => self::SDE_AMMUNITION_ME_II, 'bonus' => 2.4, 'category' => 'M-Set Ammunition ME', 'size' => 'M', 'targetCategories' => ['ammunition']];
        ksort($expected);
        ksort($rig);
        $this->assertSame($expected, $rig);
    }

    #[Group('issue-1331')]
    public function testEfficiencyRigStatesItsMaterialAndItsTimeBonus(): void
    {
        $rig = $this->offeredRigs()[self::SDE_L_SET_BASIC_LARGE_SHIP_EFFICIENCY_II];

        $this->assertSame([2.4, 24.0], [$rig['bonus'], $rig['timeBonus']]);
        $this->assertArrayNotHasKey('costBonus', $rig);
    }

    #[Group('issue-1331')]
    public function testThukkerCapitalComponentRigFallsBackOnItsCapitalComponentMaterialBonus(): void
    {
        $rig = $this->offeredRigs()[self::SDE_M_SET_THUKKER_BASIC_CAPITAL_COMPONENT];

        $this->assertSame(3.7, $rig['bonus']);
        $this->assertArrayNotHasKey('timeBonus', $rig);
    }

    #[Group('issue-1331')]
    public function testThukkerStructureAndComponentRigStatesItsGenericMaterialBonusAndItsTimeBonus(): void
    {
        $rig = $this->offeredRigs()[self::SDE_XL_SET_THUKKER_STRUCTURE_AND_COMPONENT];

        $this->assertSame([2.0, 20.0], [$rig['bonus'], $rig['timeBonus']]);
    }

    #[Group('issue-1331')]
    public function testThukkerRigCarryingBothBonusesStatesTheCapitalComponentOneAsACategoryBonus(): void
    {
        $offered = $this->offeredRigs();

        $this->assertSame(['basic_capital_component' => 3.7], $offered[self::SDE_XL_SET_THUKKER_STRUCTURE_AND_COMPONENT]['categoryBonuses']);
        $this->assertArrayNotHasKey('categoryBonuses', $offered[self::SDE_AMMUNITION_ME_II]);
    }

    #[Group('issue-1331')]
    public function testThukkerRigCarryingBothBonusesButNotTargetingCapitalComponentsStatesNoCategoryBonus(): void
    {
        $rig = $this->offeredRigs()[self::SDE_M_SET_THUKKER_ADVANCED_COMPONENT];

        $this->assertSame(2.0, $rig['bonus']);
        $this->assertArrayNotHasKey('categoryBonuses', $rig);
    }

    #[Group('issue-1331')]
    public function testLaboratoryRigsHaveNoMaterialBonusAndStateTheirTimeAndCostBonuses(): void
    {
        $offered = $this->offeredRigs();
        $optimization = $offered[self::SDE_XL_SET_LABORATORY_OPTIMIZATION_II];
        $accelerator = $offered[self::SDE_M_SET_BLUEPRINT_COPY_ACCELERATOR_II];

        $this->assertSame([0.0, 24.0, 12.0], [$optimization['bonus'], $optimization['timeBonus'], $optimization['costBonus']]);
        $this->assertSame([0.0, 24.0], [$accelerator['bonus'], $accelerator['timeBonus']]);
        $this->assertArrayNotHasKey('costBonus', $accelerator);
    }

    #[Group('issue-1331')]
    public function testTimeRigWithoutMaterialBonusStatesAMaterialBonusOfZero(): void
    {
        $rig = $this->offeredRigs()[self::SDE_EQUIPMENT_TE_I];

        $this->assertSame([0.0, 20.0], [$rig['bonus'], $rig['timeBonus']]);
    }

    #[Group('issue-1331')]
    public function testReactionRigsReadTheReactionAttributes(): void
    {
        $offered = $this->offeredRigs();
        $material = $offered[self::SDE_COMPOSITE_REACTOR_ME_II];
        $efficiency = $offered[self::SDE_L_SET_REACTOR_EFFICIENCY_II];

        $this->assertSame(2.4, $material['bonus']);
        $this->assertArrayNotHasKey('timeBonus', $material);
        $this->assertSame([2.4, 24.0], [$efficiency['bonus'], $efficiency['timeBonus']]);
    }

    #[Group('issue-1331')]
    public function testOnlyTheCatalogueRigsKnownToTheSdeAreOffered(): void
    {
        $offeredNames = array_keys($this->offeredRigs());
        sort($offeredNames);

        $expectedNames = [
            self::SDE_AMMUNITION_ME_II,
            self::SDE_EQUIPMENT_TE_I,
            self::SDE_COMPOSITE_REACTOR_ME_II,
            self::SDE_M_SET_THUKKER_BASIC_CAPITAL_COMPONENT,
            self::SDE_M_SET_THUKKER_ADVANCED_COMPONENT,
            self::SDE_XL_SET_THUKKER_STRUCTURE_AND_COMPONENT,
            self::SDE_XL_SET_LABORATORY_OPTIMIZATION_II,
            self::SDE_M_SET_BLUEPRINT_COPY_ACCELERATOR_II,
            self::SDE_L_SET_REACTOR_EFFICIENCY_II,
            self::SDE_L_SET_BASIC_LARGE_SHIP_EFFICIENCY_II,
            self::SDE_M_SET_BASIC_LARGE_SHIP_ME_I,
        ];
        sort($expectedNames);
        $this->assertSame($expectedNames, $offeredNames);
    }

    #[Group('issue-1331')]
    public function testRigsAreGroupedByFamilyAsInTheCatalogue(): void
    {
        $options = $this->provider()->getRigOptionsArray();

        $this->assertSame(['manufacturing', 'reaction'], array_keys($options));
        $this->assertSame(
            [self::SDE_COMPOSITE_REACTOR_ME_II, self::SDE_L_SET_REACTOR_EFFICIENCY_II],
            array_column($options['reaction'], 'name'),
        );
    }

    #[Group('issue-1331')]
    public function testTheSdeIsAskedForTheRigNamesInOneCall(): void
    {
        $rigTypes = $this->createMock(InvTypeRepository::class);
        $rigTypes->expects($this->once())->method('findByNames')->willReturnCallback(static fn (array $names): array => self::sdeRigTypesNamed($names));

        (new RigOptionsProvider($rigTypes, $this->sdeRigBonusReader()))->getRigOptionsArray();
    }

    private function provider(): RigOptionsProvider
    {
        return new RigOptionsProvider($this->sdeRigTypes(), $this->sdeRigBonusReader());
    }

    private function offeredRigs(): array
    {
        $byName = [];
        foreach ($this->provider()->getRigOptionsArray() as $rigs) {
            foreach ($rigs as $rig) {
                $byName[$rig['name']] = $rig;
            }
        }

        return $byName;
    }

    private function frozenBonuses(): array
    {
        return json_decode((string) file_get_contents(dirname(__DIR__, 4).self::FROZEN_BONUSES_PATH), true, flags: JSON_THROW_ON_ERROR);
    }
}
