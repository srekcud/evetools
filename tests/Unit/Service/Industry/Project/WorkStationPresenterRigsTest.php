<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project;

use App\Industry\Application\ProductionStructure;
use App\Industry\Application\StructureRig;
use App\Industry\Application\StructureType;
use App\Industry\Application\WorkStationRow;
use App\Industry\Application\WorkType;
use App\Industry\Application\WorkTypeAdvice;
use App\Industry\Domain\ActivityKind;
use App\Industry\Domain\Multiplier;
use App\Industry\Domain\RigBonus;
use App\Industry\Domain\RigSecurityModifiers;
use App\Industry\Domain\SecurityClass;
use App\Service\Industry\Project\WorkStationPresenter;
use App\Tests\Unit\Industry\Application\Double\StructureTotalCostExample as Example;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(WorkStationPresenter::class)]
#[Group('issue-1474')]
final class WorkStationPresenterRigsTest extends TestCase
{
    private const string DRONE = 'drone';
    private const string FIGHTER = 'fighter';
    private const float NULL_SEC_DRONE_RIG_EFFECTIVE_PERCENT = 6.0;

    public function testRowExposesTheMaterialRigsTakenIntoAccountWithTheirEffectiveBonus(): void
    {
        $row = $this->presentedRow(Example::riggedStructure('C', Example::THREE_JUMPS_SYSTEM_ID), Example::PRODUCT_CATEGORY);

        $this->assertSame([['categories' => [Example::PRODUCT_CATEGORY], 'bonusPercent' => Example::RIG_REDUCTION_PERCENT]], $row['rigs']);
        $this->assertSame(Example::PRODUCT_CATEGORY, $row['productCategory']);
        $this->assertFalse($row['noRigForProduct']);
    }

    public function testBonusIsTheEffectivePercentInTheSecurityOfTheStructureNotTheRawOne(): void
    {
        $row = $this->presentedRow($this->nullSecStructure($this->droneRig(1.0, 3.0)), self::DRONE);

        $this->assertSame([['categories' => [self::DRONE, self::FIGHTER], 'bonusPercent' => self::NULL_SEC_DRONE_RIG_EFFECTIVE_PERCENT]], $row['rigs']);
        $this->assertFalse($row['noRigForProduct']);
    }

    public function testStructureWithoutAnyRigIsFlaggedForTheProductCategory(): void
    {
        $row = $this->presentedRow(Example::workshopStructureWithoutRig(), self::DRONE);

        $this->assertSame([], $row['rigs']);
        $this->assertTrue($row['noRigForProduct']);
    }

    public function testStructureWhoseRigsTargetOtherCategoriesIsFlaggedAndStillListsItsRigs(): void
    {
        $row = $this->presentedRow(Example::riggedStructure('C', Example::THREE_JUMPS_SYSTEM_ID), self::DRONE);

        $this->assertCount(1, $row['rigs']);
        $this->assertTrue($row['noRigForProduct']);
    }

    public function testRowWithoutProductCategoryIsNeverFlagged(): void
    {
        $row = $this->presentedRow(Example::workshopStructureWithoutRig(), null);

        $this->assertNull($row['productCategory']);
        $this->assertFalse($row['noRigForProduct']);
    }

    public function testRigWithoutSecurityModifierThereHasAnUnknownBonusNeverZero(): void
    {
        $structure = $this->nullSecStructure(new StructureRig(new RigBonus(2.0, new RigSecurityModifiers(1.0, 1.9, null)), [self::DRONE]));

        $row = $this->presentedRow($structure, self::DRONE);

        $this->assertNull($row['rigs'][0]['bonusPercent']);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentedRow(ProductionStructure $structure, ?string $productCategory): array
    {
        $row = new WorkStationRow($structure, false, null, Multiplier::one(), null, null, null, [], null, null, [], $productCategory);
        $advice = new WorkTypeAdvice(WorkType::FinalProducts, ActivityKind::Manufacturing, 1, 1, null, null, false, null, true, [$row]);
        $labels = [$structure->id => ['name' => 'Structure '.$structure->id, 'kind' => 'raitaru']];

        return WorkStationPresenter::of($advice, null, null, $labels, [])['rows'][0];
    }

    private function droneRig(float $highSec, float $nullSec): StructureRig
    {
        return new StructureRig(new RigBonus(2.0, new RigSecurityModifiers($highSec, 1.9, $nullSec)), [self::DRONE, self::FIGHTER]);
    }

    private function nullSecStructure(StructureRig $rig): ProductionStructure
    {
        return new ProductionStructure('N', StructureType::EngineeringComplex, SecurityClass::NullSec, Example::WORKSHOP_SYSTEM_ID, new \DateTimeImmutable('2026-01-01 00:00:00'), Multiplier::one(), [$rig], Multiplier::one(), 0.01);
    }
}
