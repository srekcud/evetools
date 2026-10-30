<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Industry;

use App\Entity\CachedCharacterSkill;
use App\Entity\Character;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectStep;
use App\Entity\IndustryStepJobMatch;
use App\Entity\IndustryStepPurchase;
use App\Entity\IndustryStructureConfig;
use App\Entity\Sde\IndustryActivity;
use App\Entity\User;
use App\Enum\IndustryActivityType;
use App\Repository\CachedCharacterSkillRepository;
use App\Repository\CachedIndustryJobRepository;
use App\Repository\CachedStructureRepository;
use App\Repository\IndustryStructureConfigRepository;
use App\Repository\IndustryUserSettingsRepository;
use App\Repository\Sde\IndustryActivityRepository;
use App\Repository\Sde\StaStationRepository;
use App\Service\Industry\IndustryBonusService;
use App\Service\Industry\IndustryCalculationService;
use App\Service\Industry\InventionService;
use App\Service\TypeNameResolver;
use App\State\Provider\Industry\IndustryResourceMapper;
use Doctrine\ORM\EntityManagerInterface;
use App\Tests\Unit\Service\Industry\AutowiresByType;
use App\Tests\Unit\Service\Industry\ResolvesTypeNamesFromSde;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(IndustryResourceMapper::class)]
class IndustryResourceMapperTest extends TestCase
{
    use AutowiresByType;
    use ResolvesTypeNamesFromSde;

    private const int NITROGEN_FUEL_BLOCK_BLUEPRINT_TYPE_ID = 4314;
    private const int ROBOTICS_BLUEPRINT_TYPE_ID = 9849;
    private const int HYDROGEN_FUEL_BLOCK_BLUEPRINT_TYPE_ID = 4316;
    private const int NITROGEN_FUEL_BLOCK_BASE_TIME_PER_RUN = 900;

    private array $cachedSkillsByCharacterName = [];

    public function testStructureResourceExposesReactionMaterialBonusWithReactionSecurityMultiplier(): void
    {
        $mapper = self::autowired(IndustryResourceMapper::class, [
            $this->createStub(IndustryCalculationService::class),
            $this->createStub(CachedCharacterSkillRepository::class),
            $this->createStub(CachedIndustryJobRepository::class),
            $this->createStub(InventionService::class),
        ]);

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
        $mapper = self::autowired(IndustryResourceMapper::class, [
            $this->createStub(IndustryCalculationService::class),
            $this->createStub(CachedCharacterSkillRepository::class),
            $this->createStub(CachedIndustryJobRepository::class),
            $this->createStub(InventionService::class),
        ]);

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
        $mapper = self::autowired(IndustryResourceMapper::class, [
            $this->createStub(IndustryCalculationService::class),
            $this->createStub(CachedCharacterSkillRepository::class),
            $this->createStub(CachedIndustryJobRepository::class),
            $this->createStub(InventionService::class),
        ]);

        $station = new IndustryStructureConfig();
        $station->setName('Hek VIII');
        $station->setStructureType('station');
        $station->setSecurityType('lowsec');
        $station->setFacilityTaxPercent(10.0);

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
        $mapper = self::autowired(IndustryResourceMapper::class, [
            $this->calculationServiceOnSdeKnowingNitrogenAndHydrogenFuelBlocks(),
            $this->createStub(CachedCharacterSkillRepository::class),
            $this->createStub(CachedIndustryJobRepository::class),
            $this->createStub(InventionService::class),
        ]);
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

    #[Group('issue-183')]
    public function testProjectWithUnknownMaterialCostHasUnknownTotalCostAndProfit(): void
    {
        $project = $this->project(self::NITROGEN_FUEL_BLOCK_TYPE_ID, null)
            ->setMaterialCost(null)
            ->setEstimatedMaterialCost(null)
            ->setEstimatedJobCost(5_000.0)
            ->setEstimatedTaxAmount(3_000.0)
            ->setEstimatedSellPrice(100_000.0);

        $resource = $this->mapperOnSdeKnowingOnlyNitrogenFuelBlock()->projectToResource($project);

        self::assertNull($resource->totalCost);
        self::assertNull($resource->profit);
        self::assertNull($resource->profitPercent);
    }

    #[Group('issue-183')]
    public function testProjectWithUnknownJobsCostHasUnknownTotalCostAndProfit(): void
    {
        $project = $this->project(self::NITROGEN_FUEL_BLOCK_TYPE_ID, null)
            ->setEstimatedMaterialCost(50_000.0)
            ->setEstimatedJobCost(null)
            ->setTaxAmount(3_000.0)
            ->setSellPrice(100_000.0);

        $resource = $this->mapperOnSdeKnowingOnlyNitrogenFuelBlock()->projectToResource($project);

        self::assertNull($resource->totalCost);
        self::assertNull($resource->profit);
        self::assertNull($resource->profitPercent);
    }

    #[Group('issue-183')]
    public function testProjectWithUnknownTaxAmountHasUnknownTotalCostAndProfit(): void
    {
        $project = $this->project(self::NITROGEN_FUEL_BLOCK_TYPE_ID, null)
            ->setMaterialCost(50_000.0)
            ->setEstimatedJobCost(5_000.0)
            ->setTaxAmount(null)
            ->setEstimatedTaxAmount(null)
            ->setSellPrice(100_000.0);

        $resource = $this->mapperOnSdeKnowingOnlyNitrogenFuelBlock()->projectToResource($project);

        self::assertNull($resource->totalCost);
        self::assertNull($resource->profit);
        self::assertNull($resource->profitPercent);
    }

    #[Group('issue-183')]
    public function testProjectWithAllCostsKnownKeepsItsTotalCostAndProfit(): void
    {
        $project = $this->project(self::NITROGEN_FUEL_BLOCK_TYPE_ID, null)
            ->setBpoCost(null)
            ->setTransportCost(null)
            ->setMaterialCost(50_000.0)
            ->setEstimatedJobCost(5_000.0)
            ->setTaxAmount(3_000.0)
            ->setSellPrice(100_000.0);

        $resource = $this->mapperOnSdeKnowingOnlyNitrogenFuelBlock()->projectToResource($project);

        self::assertSame(58_000.0, $resource->totalCost);
        self::assertSame(42_000.0, $resource->profit);
        self::assertEqualsWithDelta(72.413793, $resource->profitPercent, 0.000001);
    }

    #[Group('issue-183')]
    public function testProjectWithRealJobsCostAndNoEstimatedJobsCostKeepsItsTotalCost(): void
    {
        $project = $this->project(self::NITROGEN_FUEL_BLOCK_TYPE_ID, null)
            ->setMaterialCost(50_000.0)
            ->setEstimatedJobCost(null)
            ->setTaxAmount(3_000.0)
            ->setSellPrice(100_000.0);
        $step = $this->rootStep($project, self::NITROGEN_FUEL_BLOCK_TYPE_ID, self::NITROGEN_FUEL_BLOCK_BLUEPRINT_TYPE_ID, 40, 10, 20);
        $step->addJobMatch(
            (new IndustryStepJobMatch())
                ->setEsiJobId(700_000_001)
                ->setRuns(40)
                ->setCost(4_000.0)
                ->setStatus('delivered')
                ->setCharacterName('Test Pilot'),
        );

        $resource = $this->mapperOnSdeKnowingOnlyNitrogenFuelBlock()->projectToResource($project);

        self::assertSame(4_000.0, $resource->jobsCost);
        self::assertSame(57_000.0, $resource->totalCost);
        self::assertSame(43_000.0, $resource->profit);
    }

    #[Group('issue-183')]
    public function testProjectWithUnknownMaterialCostAndNoSellPriceKeepsTheEntityProfit(): void
    {
        $project = $this->project(self::NITROGEN_FUEL_BLOCK_TYPE_ID, null)
            ->setMaterialCost(null)
            ->setEstimatedMaterialCost(null)
            ->setEstimatedJobCost(5_000.0)
            ->setTaxAmount(3_000.0)
            ->setSellPrice(null)
            ->setEstimatedSellPrice(null);

        $resource = $this->mapperOnSdeKnowingOnlyNitrogenFuelBlock()->projectToResource($project);

        self::assertNull($resource->totalCost);
        self::assertNull($resource->profit);
        self::assertNull($resource->profitPercent);
    }

    #[Group('issue-183')]
    public function testPersonalUseProjectWithUnknownMaterialCostKeepsTheEntityProfit(): void
    {
        $project = $this->project(self::NITROGEN_FUEL_BLOCK_TYPE_ID, null)
            ->setPersonalUse(true)
            ->setMaterialCost(null)
            ->setEstimatedMaterialCost(null)
            ->setEstimatedJobCost(5_000.0)
            ->setTaxAmount(3_000.0)
            ->setSellPrice(100_000.0);

        $resource = $this->mapperOnSdeKnowingOnlyNitrogenFuelBlock()->projectToResource($project);

        self::assertNull($resource->totalCost);
        self::assertSame(97_000.0, $resource->profit);
        self::assertEqualsWithDelta(3233.333333, $resource->profitPercent, 0.000001);
    }

    #[Group('issue-282')]
    public function testStepOfAPilotWhoseOnlyCharacterHasNoCachedSkillHasAnUnknownTimePerRunAndNoRecommendedCharacter(): void
    {
        $project = $this->project(self::NITROGEN_FUEL_BLOCK_TYPE_ID, null)->setUser($this->pilotWithCachedSkills(['Fresh' => []]));
        $step = $this->rootStep($project, self::NITROGEN_FUEL_BLOCK_TYPE_ID, self::NITROGEN_FUEL_BLOCK_BLUEPRINT_TYPE_ID, 40, 10, 10);

        $resource = $this->mapperOnTheBaseTimeOfTheNitrogenFuelBlockAndTheCachedSkills()->stepToResource($step);

        self::assertSame([null, null], [$resource->timePerRun, $resource->recommendedCharacterName]);
    }

    #[Group('issue-282')]
    public function testStepOfAPilotWithAKnownCharacterHasTheTimePerRunOfItsSkills(): void
    {
        $project = $this->project(self::NITROGEN_FUEL_BLOCK_TYPE_ID, null)->setUser($this->pilotWithCachedSkills([
            'Fresh' => [],
            'Main' => [CachedCharacterSkill::SKILL_INDUSTRY => 4, CachedCharacterSkill::SKILL_ADVANCED_INDUSTRY => 2],
        ]));
        $step = $this->rootStep($project, self::NITROGEN_FUEL_BLOCK_TYPE_ID, self::NITROGEN_FUEL_BLOCK_BLUEPRINT_TYPE_ID, 40, 10, 10);

        $resource = $this->mapperOnTheBaseTimeOfTheNitrogenFuelBlockAndTheCachedSkills()->stepToResource($step);

        self::assertSame([640, 'Main'], [$resource->timePerRun, $resource->recommendedCharacterName]);
    }

    private function pilotWithCachedSkills(array $skillLevelsByCharacterName): User
    {
        $pilot = new User();
        foreach ($skillLevelsByCharacterName as $characterName => $skillLevels) {
            $character = (new Character())->setName($characterName);
            $pilot->addCharacter($character);
            $this->cachedSkillsByCharacterName[$characterName] = array_map(
                static fn (int $skillId, int $level): CachedCharacterSkill => (new CachedCharacterSkill())->setCharacter($character)->setSkillId($skillId)->setLevel($level),
                array_keys($skillLevels),
                array_values($skillLevels),
            );
        }

        return $pilot;
    }

    private function mapperOnTheBaseTimeOfTheNitrogenFuelBlockAndTheCachedSkills(): IndustryResourceMapper
    {
        $activityRepository = $this->createStub(IndustryActivityRepository::class);
        $activityRepository->method('findByTypeIdsAndActivityIds')->willReturn([
            self::NITROGEN_FUEL_BLOCK_BLUEPRINT_TYPE_ID.'-'.IndustryActivityType::Manufacturing->value => (new IndustryActivity())
                ->setTypeId(self::NITROGEN_FUEL_BLOCK_BLUEPRINT_TYPE_ID)
                ->setActivityId(IndustryActivityType::Manufacturing->value)
                ->setTime(self::NITROGEN_FUEL_BLOCK_BASE_TIME_PER_RUN),
        ]);
        $skillRepository = $this->createStub(CachedCharacterSkillRepository::class);
        $skillRepository->method('findAllSkillsForCharacter')->willReturnCallback(
            fn (Character $character): array => $this->cachedSkillsByCharacterName[$character->getName()],
        );

        return self::autowired(IndustryResourceMapper::class, [
            new IndustryCalculationService(
                new TypeNameResolver($this->sdeTypeRepositoryKnowingOnlyNitrogenFuelBlock()),
                $this->createStub(IndustryBonusService::class),
                $this->createStub(IndustryStructureConfigRepository::class),
                $this->createStub(IndustryUserSettingsRepository::class),
                $this->createStub(EntityManagerInterface::class),
                $this->createStub(StaStationRepository::class),
                $this->createStub(CachedStructureRepository::class),
                $activityRepository,
                $skillRepository,
            ),
            $this->createStub(CachedIndustryJobRepository::class),
            $this->createStub(InventionService::class),
        ]);
    }

    private function mapperOnSdeKnowingOnlyNitrogenFuelBlock(): IndustryResourceMapper
    {
        return self::autowired(IndustryResourceMapper::class, [
            $this->calculationServiceOnSdeKnowingOnlyNitrogenFuelBlock(),
            $this->createStub(CachedCharacterSkillRepository::class),
            $this->createStub(CachedIndustryJobRepository::class),
            $this->createStub(InventionService::class),
        ]);
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
