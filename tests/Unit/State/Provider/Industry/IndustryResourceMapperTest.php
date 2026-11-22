<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Industry;

use App\Entity\IndustryStructureConfig;
use App\State\Provider\Industry\IndustryResourceMapper;
use App\Tests\Unit\Support\StubsSdeStructureBonuses;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(IndustryResourceMapper::class)]
class IndustryResourceMapperTest extends TestCase
{
    use StubsSdeStructureBonuses;

    public function testStructureResourceExposesReactionMaterialBonusWithReactionSecurityMultiplier(): void
    {
        $mapper = new IndustryResourceMapper($this->sdeStructureBonusSummary());

        $tatara = new IndustryStructureConfig();
        $tatara->setName('Nullsec Tatara');
        $tatara->setStructureType('tatara');
        $tatara->setSecurityType('nullsec');
        $tatara->setRigs([self::SDE_COMPOSITE_REACTOR_ME_II]);

        $resource = $mapper->structureToResource($tatara);

        $this->assertSame(2.64, $resource->reactionMaterialBonus);
        $this->assertSame(0.0, $resource->manufacturingMaterialBonus);
    }

    public function testStructureResourceExposesStandardLargeRigTimeBonusAtTheSdeScale(): void
    {
        $mapper = new IndustryResourceMapper($this->sdeStructureBonusSummary());

        $azbel = new IndustryStructureConfig();
        $azbel->setName('Nullsec Azbel');
        $azbel->setStructureType('azbel');
        $azbel->setSecurityType('nullsec');
        $azbel->setRigs([self::SDE_L_SET_BASIC_LARGE_SHIP_EFFICIENCY_II]);

        $resource = $mapper->structureToResource($azbel);

        $this->assertSame(60.32, $resource->manufacturingTimeBonus);
    }

    #[Group('issue-1331')]
    public function testStructureResourceExposesTheRigNamesMissingFromTheSdeOnce(): void
    {
        $mapper = new IndustryResourceMapper($this->sdeStructureBonusSummary());

        $sotiyo = new IndustryStructureConfig();
        $sotiyo->setName('Nullsec Sotiyo');
        $sotiyo->setStructureType('sotiyo');
        $sotiyo->setSecurityType('nullsec');
        $sotiyo->setRigs([self::SDE_XL_SET_LABORATORY_OPTIMIZATION_II, self::SDE_REMOVED_M_SET_LABORATORY_OPTIMIZATION_II, self::SDE_REMOVED_M_SET_LABORATORY_OPTIMIZATION_II]);

        $resource = $mapper->structureToResource($sotiyo);

        $this->assertSame([self::SDE_REMOVED_M_SET_LABORATORY_OPTIMIZATION_II], $resource->unrecognisedRigs);
        $this->assertSame(30.0, $resource->manufacturingTimeBonus);
    }

    #[Group('issue-1343')]
    public function testStructureResourceOfALegacyTypeIsAHullToSpecifyWithUnknownTimeBonusesAndItsMaterialBonus(): void
    {
        $mapper = new IndustryResourceMapper($this->sdeStructureBonusSummary());

        $legacy = new IndustryStructureConfig();
        $legacy->setName('Legacy Complex');
        $legacy->setStructureType('engineering_complex');
        $legacy->setSecurityType('nullsec');
        $legacy->setRigs([self::SDE_AMMUNITION_ME_II]);

        $resource = $mapper->structureToResource($legacy);

        $this->assertTrue($resource->hullToSpecify);
        $this->assertNull($resource->manufacturingTimeBonus);
        $this->assertNull($resource->reactionTimeBonus);
        $this->assertSame(5.04, $resource->manufacturingMaterialBonus);
    }

    #[Group('issue-1343')]
    public function testStructureResourceOfAKnownHullIsNotAHullToSpecifyAndHasNumericTimeBonuses(): void
    {
        $mapper = new IndustryResourceMapper($this->sdeStructureBonusSummary());

        $raitaru = new IndustryStructureConfig();
        $raitaru->setName('Highsec Raitaru');
        $raitaru->setStructureType('raitaru');
        $raitaru->setSecurityType('highsec');
        $raitaru->setRigs([]);

        $resource = $mapper->structureToResource($raitaru);

        $this->assertFalse($resource->hullToSpecify);
        $this->assertSame(15.0, $resource->manufacturingTimeBonus);
        $this->assertSame(0.0, $resource->reactionTimeBonus);
    }

    public function testStructureResourceExposesTheGameFacilityTaxForAnNpcStationStoredWithAnotherTax(): void
    {
        $mapper = new IndustryResourceMapper($this->sdeStructureBonusSummary());

        $station = new IndustryStructureConfig();
        $station->setName('Hek VIII');
        $station->setStructureType('station');
        $station->setSecurityType('lowsec');
        $station->setFacilityTaxPercent(10.0);

        $exposed = get_object_vars($mapper->structureToResource($station));

        $this->assertArrayHasKey('facilityTaxRate', $exposed);
        $this->assertSame(0.25, $exposed['facilityTaxRate']);
    }
}
