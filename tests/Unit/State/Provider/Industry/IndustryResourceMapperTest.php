<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Industry;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectStep;
use App\Entity\IndustryStepPurchase;
use App\Entity\IndustryStructureConfig;
use App\Entity\User;
use App\Repository\CachedCharacterSkillRepository;
use App\Repository\CachedIndustryJobRepository;
use App\Service\Industry\IndustryCalculationService;
use App\Service\Industry\InventionService;
use App\State\Provider\Industry\IndustryResourceMapper;
use App\Tests\Unit\Service\Industry\ResolvesTypeNamesFromSde;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(IndustryResourceMapper::class)]
class IndustryResourceMapperTest extends TestCase
{
    use ResolvesTypeNamesFromSde;

    private const int NITROGEN_FUEL_BLOCK_BLUEPRINT_TYPE_ID = 4314;
    private const int ROBOTICS_BLUEPRINT_TYPE_ID = 9849;
    private const int HYDROGEN_FUEL_BLOCK_BLUEPRINT_TYPE_ID = 4316;

    public function testStructureResourceExposesReactionMaterialBonusWithReactionSecurityMultiplier(): void
    {
        $mapper = new IndustryResourceMapper(
            $this->createStub(IndustryCalculationService::class),
            $this->createStub(CachedCharacterSkillRepository::class),
            $this->createStub(CachedIndustryJobRepository::class),
            $this->createStub(InventionService::class),
        );

        $tatara = new IndustryStructureConfig();
        $tatara->setName('Nullsec Tatara');
        $tatara->setStructureType('tatara');
        $tatara->setSecurityType('nullsec');
        $tatara->setRigs(['Standup M-Set Composite Reactor Material Efficiency II']);

        $resource = $mapper->structureToResource($tatara);

        // Issue #72: 2.4% x 1.1 (nullsec reaction multiplier), not x 2.1 (manufacturing)
        $this->assertSame(2.64, $resource->reactionMaterialBonus);
        $this->assertSame(0.0, $resource->manufacturingMaterialBonus);
    }

    public function testStructureResourceExposesStandardLargeRigTimeBonusAtTheSdeScale(): void
    {
        $mapper = new IndustryResourceMapper(
            $this->createStub(IndustryCalculationService::class),
            $this->createStub(CachedCharacterSkillRepository::class),
            $this->createStub(CachedIndustryJobRepository::class),
            $this->createStub(InventionService::class),
        );

        $azbel = new IndustryStructureConfig();
        $azbel->setName('Nullsec Azbel');
        $azbel->setStructureType('azbel');
        $azbel->setSecurityType('nullsec');
        $azbel->setRigs(['Standup L-Set Basic Large Ship Manufacturing Efficiency II']);

        $resource = $mapper->structureToResource($azbel);

        // Issue #94: rig 24 % x 2.1 = 50.4 %, stacked with the Azbel 20 %: 1 - 0.80 x (1 - 0.504)
        $this->assertSame(60.32, $resource->manufacturingTimeBonus);
    }

    public function testStructureResourceExposesTheGameFacilityTaxForAnNpcStationStoredWithAnotherTax(): void
    {
        $mapper = new IndustryResourceMapper(
            $this->createStub(IndustryCalculationService::class),
            $this->createStub(CachedCharacterSkillRepository::class),
            $this->createStub(CachedIndustryJobRepository::class),
            $this->createStub(InventionService::class),
        );

        $station = new IndustryStructureConfig();
        $station->setName('Hek VIII');
        $station->setStructureType('station');
        $station->setSecurityType('lowsec');
        $station->setFacilityTaxRate(10.0);

        $exposed = get_object_vars($mapper->structureToResource($station));

        $this->assertArrayHasKey('facilityTaxRate', $exposed);
        $this->assertSame(0.25, $exposed['facilityTaxRate']);
    }

    #[Group('issue-115')]
    public function testStepPurchaseAndProjectOfAKnownTypeKeepTheirSdeNames(): void
    {
        $mapper = $this->mapperOnSdeKnowingOnlyNitrogenFuelBlock();
        $project = $this->project(self::NITROGEN_FUEL_BLOCK_TYPE_ID, null);
        $step = $this->rootStep($project, self::NITROGEN_FUEL_BLOCK_TYPE_ID, self::NITROGEN_FUEL_BLOCK_BLUEPRINT_TYPE_ID, 40, 10, 20);
        $purchase = $this->purchase($step, self::NITROGEN_FUEL_BLOCK_TYPE_ID, 400, 15_000.0);

        $stepResource = $mapper->stepToResource($step);
        $purchaseResource = $mapper->purchaseToResource($purchase);
        $projectResource = $mapper->projectToResource($project);

        self::assertSame(self::NITROGEN_FUEL_BLOCK_NAME, $stepResource->productTypeName);
        self::assertSame(self::NITROGEN_FUEL_BLOCK_NAME, $stepResource->purchases[0]['typeName']);
        self::assertSame(self::NITROGEN_FUEL_BLOCK_NAME, $purchaseResource->typeName);
        self::assertSame(self::NITROGEN_FUEL_BLOCK_NAME, $projectResource->productTypeName);
        self::assertSame(self::NITROGEN_FUEL_BLOCK_NAME, $projectResource->displayName);
    }

    #[Group('issue-115')]
    public function testStepWhoseProductIsMissingFromTheSdeHasAnUnknownTypeName(): void
    {
        $mapper = $this->mapperOnSdeKnowingOnlyNitrogenFuelBlock();
        $project = $this->project(self::ROBOTICS_TYPE_ID, null);
        $step = $this->rootStep($project, self::ROBOTICS_TYPE_ID, self::ROBOTICS_BLUEPRINT_TYPE_ID, 30, 4, 8);

        $resource = $mapper->stepToResource($step);

        self::assertSame(self::ROBOTICS_TYPE_ID, $resource->productTypeId);
        self::assertNull($resource->productTypeName);
        self::assertSame(30, $resource->runs);
        self::assertSame(30, $resource->quantity);
    }

    #[Group('issue-115')]
    public function testPurchaseOfATypeMissingFromTheSdeHasAnUnknownTypeNameAndKeepsItsQuantityAndPrice(): void
    {
        $mapper = $this->mapperOnSdeKnowingOnlyNitrogenFuelBlock();
        $project = $this->project(self::ROBOTICS_TYPE_ID, null);
        $step = $this->rootStep($project, self::ROBOTICS_TYPE_ID, self::ROBOTICS_BLUEPRINT_TYPE_ID, 30, 4, 8);
        $purchase = $this->purchase($step, self::ROBOTICS_TYPE_ID, 10, 1_000.0);

        $resource = $mapper->purchaseToResource($purchase);

        self::assertSame(self::ROBOTICS_TYPE_ID, $resource->typeId);
        self::assertNull($resource->typeName);
        self::assertSame(10, $resource->quantity);
        self::assertSame(1_000.0, $resource->unitPrice);
        self::assertSame(10_000.0, $resource->totalPrice);
    }

    #[Group('issue-115')]
    public function testPurchaseOfATypeMissingFromTheSdeListedInAStepHasAnUnknownTypeName(): void
    {
        $mapper = $this->mapperOnSdeKnowingOnlyNitrogenFuelBlock();
        $project = $this->project(self::NITROGEN_FUEL_BLOCK_TYPE_ID, null);
        $step = $this->rootStep($project, self::NITROGEN_FUEL_BLOCK_TYPE_ID, self::NITROGEN_FUEL_BLOCK_BLUEPRINT_TYPE_ID, 40, 10, 20);
        $this->purchase($step, self::ROBOTICS_TYPE_ID, 12, 1_100.0);

        $resource = $mapper->stepToResource($step);

        self::assertCount(1, $resource->purchases);
        self::assertSame(self::ROBOTICS_TYPE_ID, $resource->purchases[0]['typeId']);
        self::assertNull($resource->purchases[0]['typeName']);
        self::assertSame(12, $resource->purchases[0]['quantity']);
        self::assertSame(13_200.0, $resource->purchases[0]['totalPrice']);
        self::assertSame(self::NITROGEN_FUEL_BLOCK_NAME, $resource->productTypeName);
    }

    #[Group('issue-115')]
    public function testProjectWhoseOnlyRootProductIsMissingFromTheSdeHasNoDisplayName(): void
    {
        $mapper = $this->mapperOnSdeKnowingOnlyNitrogenFuelBlock();
        $project = $this->project(self::ROBOTICS_TYPE_ID, null);
        $this->rootStep($project, self::ROBOTICS_TYPE_ID, self::ROBOTICS_BLUEPRINT_TYPE_ID, 5, 2, 4);

        $resource = $mapper->projectToResource($project);

        self::assertSame(self::ROBOTICS_TYPE_ID, $resource->productTypeId);
        self::assertNull($resource->productTypeName);
        self::assertSame([[
            'typeId' => self::ROBOTICS_TYPE_ID,
            'typeName' => null,
            'runs' => 5,
            'meLevel' => 2,
            'teLevel' => 4,
            'count' => 1,
        ]], $resource->rootProducts);
        self::assertNull($resource->displayName);
    }

    #[Group('issue-115')]
    public function testProjectWithOneRootProductMissingFromTheSdeAmongTwoHasNoDisplayName(): void
    {
        $mapper = $this->mapperOnSdeKnowingOnlyNitrogenFuelBlock();
        $project = $this->project(self::NITROGEN_FUEL_BLOCK_TYPE_ID, null);
        $this->rootStep($project, self::NITROGEN_FUEL_BLOCK_TYPE_ID, self::NITROGEN_FUEL_BLOCK_BLUEPRINT_TYPE_ID, 40, 10, 20);
        $this->rootStep($project, self::ROBOTICS_TYPE_ID, self::ROBOTICS_BLUEPRINT_TYPE_ID, 5, 2, 4);

        $resource = $mapper->projectToResource($project);

        self::assertSame([
            [
                'typeId' => self::NITROGEN_FUEL_BLOCK_TYPE_ID,
                'typeName' => self::NITROGEN_FUEL_BLOCK_NAME,
                'runs' => 40,
                'meLevel' => 10,
                'teLevel' => 20,
                'count' => 1,
            ],
            [
                'typeId' => self::ROBOTICS_TYPE_ID,
                'typeName' => null,
                'runs' => 5,
                'meLevel' => 2,
                'teLevel' => 4,
                'count' => 1,
            ],
        ], $resource->rootProducts);
        self::assertNull($resource->displayName);
    }

    #[Group('issue-115')]
    public function testCustomNameOfAProjectWhoseProductIsMissingFromTheSdeStaysItsDisplayName(): void
    {
        $mapper = $this->mapperOnSdeKnowingOnlyNitrogenFuelBlock();
        $project = $this->project(self::ROBOTICS_TYPE_ID, 'Batch robotics');
        $this->rootStep($project, self::ROBOTICS_TYPE_ID, self::ROBOTICS_BLUEPRINT_TYPE_ID, 5, 2, 4);

        $resource = $mapper->projectToResource($project);

        self::assertSame('Batch robotics', $resource->displayName);
        self::assertNull($resource->productTypeName);
    }

    #[Group('issue-115')]
    public function testProjectWithoutRootStepWhoseProductIsMissingFromTheSdeHasNoDisplayName(): void
    {
        $mapper = $this->mapperOnSdeKnowingOnlyNitrogenFuelBlock();
        $project = $this->project(self::ROBOTICS_TYPE_ID, null);

        $resource = $mapper->projectToResource($project);

        self::assertSame([], $resource->rootProducts);
        self::assertNull($resource->displayName);
    }

    #[Group('issue-115')]
    public function testProjectWhoseOnlyRootProductMissingFromTheSdeIsSplitInTwoStepsHasNoDisplayName(): void
    {
        $mapper = $this->mapperOnSdeKnowingOnlyNitrogenFuelBlock();
        $project = $this->project(self::ROBOTICS_TYPE_ID, null);
        $this->rootStep($project, self::ROBOTICS_TYPE_ID, self::ROBOTICS_BLUEPRINT_TYPE_ID, 5, 2, 4);
        $this->rootStep($project, self::ROBOTICS_TYPE_ID, self::ROBOTICS_BLUEPRINT_TYPE_ID, 3, 2, 4);

        $resource = $mapper->projectToResource($project);

        self::assertSame([[
            'typeId' => self::ROBOTICS_TYPE_ID,
            'typeName' => null,
            'runs' => 8,
            'meLevel' => 2,
            'teLevel' => 4,
            'count' => 2,
        ]], $resource->rootProducts);
        self::assertNull($resource->displayName);
    }

    #[Group('issue-115')]
    public function testProjectWhoseOnlyRootProductIsKnownAndSplitInTwoStepsIsNamedWithItsStepCount(): void
    {
        $mapper = $this->mapperOnSdeKnowingOnlyNitrogenFuelBlock();
        $project = $this->project(self::NITROGEN_FUEL_BLOCK_TYPE_ID, null);
        $this->rootStep($project, self::NITROGEN_FUEL_BLOCK_TYPE_ID, self::NITROGEN_FUEL_BLOCK_BLUEPRINT_TYPE_ID, 40, 10, 20);
        $this->rootStep($project, self::NITROGEN_FUEL_BLOCK_TYPE_ID, self::NITROGEN_FUEL_BLOCK_BLUEPRINT_TYPE_ID, 25, 10, 20);

        $resource = $mapper->projectToResource($project);

        self::assertSame(65, $resource->rootProducts[0]['runs']);
        self::assertSame(2, $resource->rootProducts[0]['count']);
        self::assertSame('Nitrogen Fuel Block ×2', $resource->displayName);
    }

    #[Group('issue-115')]
    public function testProjectWithTwoKnownRootProductsIsNamedAfterBoth(): void
    {
        $mapper = new IndustryResourceMapper(
            $this->calculationServiceOnSdeKnowingNitrogenAndHydrogenFuelBlocks(),
            $this->createStub(CachedCharacterSkillRepository::class),
            $this->createStub(CachedIndustryJobRepository::class),
            $this->createStub(InventionService::class),
        );
        $project = $this->project(self::NITROGEN_FUEL_BLOCK_TYPE_ID, null);
        $this->rootStep($project, self::NITROGEN_FUEL_BLOCK_TYPE_ID, self::NITROGEN_FUEL_BLOCK_BLUEPRINT_TYPE_ID, 40, 10, 20);
        $this->rootStep($project, self::HYDROGEN_FUEL_BLOCK_TYPE_ID, self::HYDROGEN_FUEL_BLOCK_BLUEPRINT_TYPE_ID, 15, 8, 16);

        $resource = $mapper->projectToResource($project);

        self::assertSame(
            [self::NITROGEN_FUEL_BLOCK_NAME, self::HYDROGEN_FUEL_BLOCK_NAME],
            array_column($resource->rootProducts, 'typeName'),
        );
        self::assertSame('Nitrogen Fuel Block + Hydrogen Fuel Block', $resource->displayName);
    }

    private function mapperOnSdeKnowingOnlyNitrogenFuelBlock(): IndustryResourceMapper
    {
        return new IndustryResourceMapper(
            $this->calculationServiceOnSdeKnowingOnlyNitrogenFuelBlock(),
            $this->createStub(CachedCharacterSkillRepository::class),
            $this->createStub(CachedIndustryJobRepository::class),
            $this->createStub(InventionService::class),
        );
    }

    private function project(int $productTypeId, ?string $customName): IndustryProject
    {
        return (new IndustryProject())
            ->setUser(new User())
            ->setProductTypeId($productTypeId)
            ->setName($customName)
            ->setRuns(1);
    }

    private function rootStep(
        IndustryProject $project,
        int $productTypeId,
        int $blueprintTypeId,
        int $runs,
        int $meLevel,
        int $teLevel,
    ): IndustryProjectStep {
        $structure = new IndustryStructureConfig();
        $structure->setName('Sotiyo Delve');
        $structure->setStructureType('sotiyo');
        $structure->setSecurityType('nullsec');

        $step = (new IndustryProjectStep())
            ->setProject($project)
            ->setBlueprintTypeId($blueprintTypeId)
            ->setProductTypeId($productTypeId)
            ->setQuantity($runs)
            ->setRuns($runs)
            ->setDepth(0)
            ->setMeLevel($meLevel)
            ->setTeLevel($teLevel)
            ->setActivityType('manufacturing')
            ->setSortOrder(0)
            ->setStructureConfig($structure);
        $project->addStep($step);

        return $step;
    }

    private function purchase(IndustryProjectStep $step, int $typeId, int $quantity, float $unitPrice): IndustryStepPurchase
    {
        $purchase = (new IndustryStepPurchase())
            ->setStep($step)
            ->setTypeId($typeId)
            ->setQuantity($quantity)
            ->setUnitPrice($unitPrice)
            ->setTotalPrice($quantity * $unitPrice)
            ->setSource('manual');
        $step->addPurchase($purchase);

        return $purchase;
    }
}
