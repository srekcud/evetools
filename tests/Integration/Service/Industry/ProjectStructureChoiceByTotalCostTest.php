<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Industry;

use App\Entity\CachedCharacterSkill;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectStep;
use App\Entity\IndustryRigCategory;
use App\Entity\IndustryStructureConfig;
use App\Entity\IndustryUserSettings;
use App\Entity\Sde\IndustryActivity;
use App\Entity\Sde\IndustryActivityMaterial;
use App\Entity\Sde\IndustryActivityProduct;
use App\Entity\Sde\IndustryBlueprint;
use App\Entity\Sde\InvCategory;
use App\Entity\Sde\InvGroup;
use App\Entity\Sde\InvType;
use App\Entity\Sde\MapSolarSystemJump;
use App\Entity\User;
use App\Enum\IndustryActivityType;
use App\Industry\Application\InventionCatalog;
use App\Industry\Application\StructureSelectionPolicy;
use App\Industry\Domain\Isk;
use App\Repository\CachedCharacterSkillRepository;
use App\Repository\Sde\IndustryActivityMaterialRepository;
use App\Repository\Sde\IndustryActivityRepository;
use App\Repository\Sde\IndustryBlueprintRepository;
use App\Repository\Sde\InvTypeRepository;
use App\Service\Industry\EsiCostIndexService;
use App\Service\Industry\IndustryBlacklistService;
use App\Service\Industry\IndustryBonusService;
use App\Service\Industry\IndustryCalculationService;
use App\Service\Industry\IndustryProjectFactory;
use App\Service\Industry\IndustryTreeService;
use App\Service\Industry\TimePerRunCalculator;
use App\Service\JitaMarketService;
use App\Service\Mercure\MercurePublisherService;
use App\Tests\Integration\IntegrationTestCase;
use App\Tests\Unit\Service\Industry\AutowiresByType;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;
use Symfony\Component\Mercure\HubInterface;

#[Group('issue-341')]
final class ProjectStructureChoiceByTotalCostTest extends IntegrationTestCase
{
    use AutowiresByType;

    private const int FAVORITE_SYSTEM_ID = 30_999_901;
    private const int ONE_JUMP_SYSTEM_ID = 30_999_902;
    private const int REGION_ID = 10_999_900;
    private const int CONSTELLATION_ID = 20_999_900;

    private const int SHIP_CATEGORY_ID = 999_994_006;
    private const int FRIGATE_GROUP_ID = 999_994_025;
    private const int COMPONENT_GROUP_ID = 999_994_334;
    private const int ALLOY_GROUP_ID = 999_994_018;
    private const int RIG_GROUP_ID = 999_994_773;
    private const int FRIGATE_TYPE_ID = 999_994_587;
    private const int FRIGATE_BLUEPRINT_TYPE_ID = 999_994_588;
    private const int PLATING_TYPE_ID = 999_994_111;
    private const int PLATING_BLUEPRINT_TYPE_ID = 999_994_112;
    private const int ALLOY_TYPE_ID = 999_994_036;
    private const int MATERIAL_RIG_TYPE_ID = 999_994_501;
    private const int TIME_RIG_TYPE_ID = 999_994_502;

    private const string MATERIAL_RIG_NAME = 'Standup M-Set Basic Small Ship Manufacturing Material Efficiency II';
    private const string TIME_RIG_NAME = 'Standup M-Set Basic Small Ship Manufacturing Time Efficiency II';

    private const int RAITARU_TYPE_ID = 35825;
    private const int ATTRIBUTE_STRUCTURE_MATERIAL_BONUS = 2600;
    private const int ATTRIBUTE_HI_SEC_MODIFIER = 2355;
    private const int ATTRIBUTE_LOW_SEC_MODIFIER = 2356;
    private const int ATTRIBUTE_NULL_SEC_MODIFIER = 2357;
    private const int ATTRIBUTE_RIG_TIME_BONUS = 2593;
    private const int ATTRIBUTE_RIG_MATERIAL_BONUS = 2594;

    private const int FRIGATE_RUNS = 10;
    private const int PLATINGS_PER_FRIGATE_RUN = 100;
    private const int ALLOYS_PER_PLATING_RUN = 3;
    private const int FRIGATE_BASE_TIME_PER_RUN_SECONDS = 600;
    private const int PLATING_BASE_TIME_PER_RUN_SECONDS = 100;
    private const float PLATING_UNIT_PRICE = 5_000.0;
    private const float PLATING_ADJUSTED_PRICE = 4_000.0;
    private const float ALLOY_UNIT_PRICE = 20.0;
    private const float ALLOY_ADJUSTED_PRICE = 15.0;
    private const float COST_INDEX = 0.04;
    private const float LOW_MINIMUM_SAVING = 100_000.0;
    private const float HIGH_MINIMUM_SAVING = 1_000_000.0;

    private const int PLATINGS_NEEDED_AT_THE_FAVORITE_STRUCTURE = 990;
    private const int PLATINGS_NEEDED_AT_THE_RIGGED_STRUCTURE = 941;
    private const int TIME_PER_RUN_AT_THE_FAVORITE_STRUCTURE = 510;
    private const int TIME_PER_RUN_AT_THE_RIGGED_STRUCTURE = 253;
    private const float RIGGED_STRUCTURE_RIG_MATERIAL_BONUS = 5.04;
    private const float DELTA = 0.001;

    private User $pilot;
    private IndustryStructureConfig $favoriteStructure;
    private IndustryStructureConfig $riggedStructure;

    protected function setUp(): void
    {
        parent::setUp();
        $this->replaceMarketAndEsiSources();
        $character = $this->createCharacter(name: 'Total Cost Pilot');
        $pilot = $character->getUser();
        \assert($pilot instanceof User);
        $pilot->setMainCharacter($character);
        $this->pilot = $pilot;
        $this->em->persist((new CachedCharacterSkill())->setCharacter($character)->setSkillId(CachedCharacterSkill::SKILL_INDUSTRY)->setLevel(0)->setIsManual(true));
        $this->seedFrigateRecipe();
        $this->seedRigsAndRaitaruRoleBonus();
        $this->seedOneJumpBetweenTheTwoSystems();
        $this->em->persist((new IndustryUserSettings())->setUser($pilot)->setFavoriteManufacturingSystemId(self::FAVORITE_SYSTEM_ID));
        $this->favoriteStructure = $this->storeStructure('Favorite Raitaru', self::FAVORITE_SYSTEM_ID, []);
        $this->riggedStructure = $this->storeStructure('Rigged Raitaru', self::ONE_JUMP_SYSTEM_ID, [self::MATERIAL_RIG_NAME, self::TIME_RIG_NAME]);
        $this->em->flush();
    }

    public function testStepOfAProjectAcceptingAStructureOutsideTheFavoriteSystemGetsTheCheaperStructureOfAnotherSystem(): void
    {
        $step = $this->frigateStepOf($this->project(acceptsOutsideFavoriteSystem: true, minimumOutsideSaving: self::LOW_MINIMUM_SAVING));

        $bonus = $this->calculationService()->getStructureBonusForStep($step);

        self::assertSame('Rigged Raitaru', $bonus['name']);
        self::assertSame($this->riggedStructure, $bonus['structure']);
        self::assertEqualsWithDelta(self::RIGGED_STRUCTURE_RIG_MATERIAL_BONUS, $bonus['materialBonus']['rig'], self::DELTA);
        self::assertFalse($bonus['favoriteSystemWithoutSuitableStructure']);
        $riggedStructureAsAssigned = $this->calculationService()->assignedStructureBonusOf($this->riggedStructure, self::FRIGATE_TYPE_ID, false);
        self::assertEqualsWithDelta($riggedStructureAsAssigned['materialBonus']['total'], $bonus['materialBonus']['total'], self::DELTA);
        self::assertEqualsWithDelta($riggedStructureAsAssigned['timeBonus'], $bonus['timeBonus'], self::DELTA);
    }

    public function testStepOfAProjectRefusingAStructureOutsideTheFavoriteSystemKeepsTheStructureOfTheFavoriteSystem(): void
    {
        $step = $this->frigateStepOf($this->project(acceptsOutsideFavoriteSystem: false, minimumOutsideSaving: self::LOW_MINIMUM_SAVING));

        $bonus = $this->calculationService()->getStructureBonusForStep($step);

        self::assertSame('Favorite Raitaru', $bonus['name']);
        self::assertSame($this->favoriteStructure, $bonus['structure']);
    }

    public function testStepOfAProjectWhoseMinimumOutsideSavingIsAboveTheSavingKeepsTheStructureOfTheFavoriteSystem(): void
    {
        $step = $this->frigateStepOf($this->project(acceptsOutsideFavoriteSystem: true, minimumOutsideSaving: self::HIGH_MINIMUM_SAVING));

        $bonus = $this->calculationService()->getStructureBonusForStep($step);

        self::assertSame('Favorite Raitaru', $bonus['name']);
    }

    public function testStructureBonusOfWithAnActiveSelectionPolicyAndTheRunsOfTheJobChoosesByTotalCost(): void
    {
        $policy = new StructureSelectionPolicy(true, new Isk(self::LOW_MINIMUM_SAVING));

        $bonus = $this->calculationService()->structureBonusOf($this->pilot, self::FRIGATE_TYPE_ID, 'manufacturing', null, $policy, self::FRIGATE_RUNS);

        self::assertSame('Rigged Raitaru', $bonus['name']);
        self::assertSame($this->riggedStructure, $bonus['structure']);
    }

    public function testStructureBonusOfWithAnInactiveSelectionPolicyKeepsTheStructureOfTheFavoriteSystem(): void
    {
        $policy = new StructureSelectionPolicy(false, new Isk(0.0));

        $bonus = $this->calculationService()->structureBonusOf($this->pilot, self::FRIGATE_TYPE_ID, 'manufacturing', null, $policy, self::FRIGATE_RUNS);

        self::assertSame('Favorite Raitaru', $bonus['name']);
    }

    public function testStructureBonusOfAnAssignedStructureIsKeptWhateverTheSelectionPolicy(): void
    {
        $policy = new StructureSelectionPolicy(true, new Isk(0.0));

        $bonus = $this->calculationService()->structureBonusOf($this->pilot, self::FRIGATE_TYPE_ID, 'manufacturing', $this->favoriteStructure, $policy, self::FRIGATE_RUNS);

        self::assertSame($this->favoriteStructure, $bonus['structure']);
    }

    public function testTimePerRunOfAStepOfAProjectAcceptingAStructureOutsideTheFavoriteSystemUsesTheTimeBonusOfTheChosenStructure(): void
    {
        $accepting = $this->frigateStepOf($this->project(acceptsOutsideFavoriteSystem: true, minimumOutsideSaving: self::LOW_MINIMUM_SAVING));
        $refusing = $this->frigateStepOf($this->project(acceptsOutsideFavoriteSystem: false, minimumOutsideSaving: self::LOW_MINIMUM_SAVING));
        $calculator = self::getContainer()->get(TimePerRunCalculator::class);

        self::assertSame(self::TIME_PER_RUN_AT_THE_RIGGED_STRUCTURE, $calculator->calculateTimePerRun($accepting));
        self::assertSame(self::TIME_PER_RUN_AT_THE_FAVORITE_STRUCTURE, $calculator->calculateTimePerRun($refusing));
    }

    public function testComponentsNeededByAProjectCreatedAcceptingAStructureOutsideTheFavoriteSystemUseTheMaterialBonusOfTheChosenStructure(): void
    {
        $project = $this->createdProject(acceptsOutsideFavoriteSystem: true, minimumOutsideSaving: self::LOW_MINIMUM_SAVING);

        self::assertTrue($project->isAcceptsOutsideFavoriteSystem());
        self::assertSame(self::LOW_MINIMUM_SAVING, $project->getMinimumOutsideSaving());
        self::assertSame(self::PLATINGS_NEEDED_AT_THE_RIGGED_STRUCTURE, $this->platingStepOf($project)->getQuantity());
    }

    public function testComponentsNeededByAProjectCreatedRefusingAStructureOutsideTheFavoriteSystemUseTheFavoriteStructure(): void
    {
        $project = $this->createdProject(acceptsOutsideFavoriteSystem: false, minimumOutsideSaving: self::LOW_MINIMUM_SAVING);

        self::assertFalse($project->isAcceptsOutsideFavoriteSystem());
        self::assertSame(self::PLATINGS_NEEDED_AT_THE_FAVORITE_STRUCTURE, $this->platingStepOf($project)->getQuantity());
    }

    private function createdProject(bool $acceptsOutsideFavoriteSystem, float $minimumOutsideSaving): IndustryProject
    {
        $container = self::getContainer();
        $factory = self::autowired(IndustryProjectFactory::class, [
            $container->get(IndustryTreeService::class),
            $container->get(IndustryBlacklistService::class),
            $container->get(IndustryBonusService::class),
            $container->get(IndustryCalculationService::class),
            $container->get(CachedCharacterSkillRepository::class),
            $container->get(InvTypeRepository::class),
            $container->get(IndustryActivityMaterialRepository::class),
            $container->get(IndustryActivityRepository::class),
            $this->em,
            new MercurePublisherService($this->createStub(HubInterface::class), new NullLogger()),
            $container->get(IndustryBlueprintRepository::class),
            $container->get(InventionCatalog::class),
        ]);

        $project = $factory->createProject(
            $this->pilot,
            self::FRIGATE_TYPE_ID,
            self::FRIGATE_RUNS,
            0,
            acceptsOutsideFavoriteSystem: $acceptsOutsideFavoriteSystem,
            minimumOutsideSaving: $minimumOutsideSaving,
        );
        $projectId = $project->getId();
        $this->flushAndClear();
        $reloaded = $this->em->find(IndustryProject::class, $projectId);
        self::assertNotNull($reloaded);

        return $reloaded;
    }

    private function platingStepOf(IndustryProject $project): IndustryProjectStep
    {
        foreach ($project->getSteps() as $step) {
            if (self::PLATING_TYPE_ID === $step->getProductTypeId()) {
                return $step;
            }
        }

        self::fail('The project has no plating step.');
    }

    private function calculationService(): IndustryCalculationService
    {
        return self::getContainer()->get(IndustryCalculationService::class);
    }

    private function project(bool $acceptsOutsideFavoriteSystem, float $minimumOutsideSaving): IndustryProject
    {
        return (new IndustryProject())
            ->setOwner($this->pilot)
            ->setProductTypeId(self::FRIGATE_TYPE_ID)
            ->setRuns(self::FRIGATE_RUNS)
            ->setAcceptsOutsideFavoriteSystem($acceptsOutsideFavoriteSystem)
            ->setMinimumOutsideSaving($minimumOutsideSaving);
    }

    private function frigateStepOf(IndustryProject $project): IndustryProjectStep
    {
        return (new IndustryProjectStep())
            ->setProject($project)
            ->setProductTypeId(self::FRIGATE_TYPE_ID)
            ->setBlueprintTypeId(self::FRIGATE_BLUEPRINT_TYPE_ID)
            ->setActivityType('manufacturing')
            ->setRuns(self::FRIGATE_RUNS)
            ->setQuantity(self::FRIGATE_RUNS)
            ->setMeLevel(0)
            ->setTeLevel(0)
            ->setDepth(0);
    }

    /** @param list<string> $rigs */
    private function storeStructure(string $name, int $solarSystemId, array $rigs): IndustryStructureConfig
    {
        $structure = (new IndustryStructureConfig())
            ->setUser($this->pilot)
            ->setName($name)
            ->setSolarSystemId($solarSystemId)
            ->setSecurityType('nullsec')
            ->setStructureType('raitaru')
            ->setRigs($rigs);
        $this->em->persist($structure);

        return $structure;
    }

    private function replaceMarketAndEsiSources(): void
    {
        $sellPrices = [self::PLATING_TYPE_ID => self::PLATING_UNIT_PRICE, self::ALLOY_TYPE_ID => self::ALLOY_UNIT_PRICE];
        $jitaMarket = $this->createStub(JitaMarketService::class);
        $jitaMarket->method('getWeightedSellPricesWithFallback')->willReturnCallback(
            static fn (array $quantitiesByTypeId): array => array_map(
                static fn (int $typeId): ?array => isset($sellPrices[$typeId]) ? ['weightedPrice' => $sellPrices[$typeId], 'coverage' => 1.0, 'ordersUsed' => 1] : null,
                array_combine(array_keys($quantitiesByTypeId), array_keys($quantitiesByTypeId)),
            ),
        );
        self::getContainer()->set(JitaMarketService::class, $jitaMarket);

        $adjustedPrices = [self::PLATING_TYPE_ID => self::PLATING_ADJUSTED_PRICE, self::ALLOY_TYPE_ID => self::ALLOY_ADJUSTED_PRICE];
        $costIndices = [self::FAVORITE_SYSTEM_ID => self::COST_INDEX, self::ONE_JUMP_SYSTEM_ID => self::COST_INDEX];
        $esiCostIndex = $this->createStub(EsiCostIndexService::class);
        $esiCostIndex->method('getAdjustedPrice')->willReturnCallback(static fn (int $typeId): ?float => $adjustedPrices[$typeId] ?? null);
        $esiCostIndex->method('getCostIndex')->willReturnCallback(static fn (int $solarSystemId, string $activity): ?float => 'manufacturing' === $activity ? ($costIndices[$solarSystemId] ?? null) : null);
        self::getContainer()->set(EsiCostIndexService::class, $esiCostIndex);
    }

    private function seedFrigateRecipe(): void
    {
        $ships = (new InvCategory())->setCategoryId(self::SHIP_CATEGORY_ID)->setCategoryName('Test Ship');
        $this->em->persist($ships);
        $frigates = $this->group(self::FRIGATE_GROUP_ID, 'Test Frigate', $ships);
        $platings = $this->group(self::COMPONENT_GROUP_ID, 'Test Plating', $ships);
        $alloys = $this->group(self::ALLOY_GROUP_ID, 'Test Alloy', $ships);
        $this->group(self::RIG_GROUP_ID, 'Test Rig', $ships);
        $this->type(self::FRIGATE_TYPE_ID, 'Test Frigate', $frigates, 0.1);
        $this->type(self::PLATING_TYPE_ID, 'Test Plating', $platings, 0.01);
        $this->type(self::ALLOY_TYPE_ID, 'Test Alloy', $alloys, 0.02);
        $this->em->persist((new IndustryRigCategory())->setGroupId(self::FRIGATE_GROUP_ID)->setCategory('basic_small_ship'));

        $this->manufacturingRecipe(self::FRIGATE_BLUEPRINT_TYPE_ID, self::FRIGATE_TYPE_ID, self::PLATING_TYPE_ID, self::PLATINGS_PER_FRIGATE_RUN, self::FRIGATE_BASE_TIME_PER_RUN_SECONDS);
        $this->manufacturingRecipe(self::PLATING_BLUEPRINT_TYPE_ID, self::PLATING_TYPE_ID, self::ALLOY_TYPE_ID, self::ALLOYS_PER_PLATING_RUN, self::PLATING_BASE_TIME_PER_RUN_SECONDS);
    }

    private function manufacturingRecipe(int $blueprintTypeId, int $productTypeId, int $materialTypeId, int $baseQuantityPerRun, int $baseTimePerRunSeconds): void
    {
        $manufacturing = IndustryActivityType::Manufacturing->value;
        $this->em->persist((new IndustryBlueprint())->setTypeId($blueprintTypeId)->setMaxProductionLimit(300));
        $this->em->persist((new IndustryActivityProduct())->setTypeId($blueprintTypeId)->setActivityId($manufacturing)->setProductTypeId($productTypeId)->setQuantity(1));
        $this->em->persist((new IndustryActivityMaterial())->setTypeId($blueprintTypeId)->setActivityId($manufacturing)->setMaterialTypeId($materialTypeId)->setQuantity($baseQuantityPerRun));
        $this->em->persist((new IndustryActivity())->setTypeId($blueprintTypeId)->setActivityId($manufacturing)->setTime($baseTimePerRunSeconds));
    }

    private function seedRigsAndRaitaruRoleBonus(): void
    {
        $rigGroup = $this->em->find(InvGroup::class, self::RIG_GROUP_ID);
        \assert($rigGroup instanceof InvGroup);
        $this->type(self::MATERIAL_RIG_TYPE_ID, self::MATERIAL_RIG_NAME, $rigGroup, 0.0);
        $this->type(self::TIME_RIG_TYPE_ID, self::TIME_RIG_NAME, $rigGroup, 0.0);
        $this->em->flush();

        $connection = self::getContainer()->get(Connection::class);
        $this->insertAttribute($connection, self::RAITARU_TYPE_ID, self::ATTRIBUTE_STRUCTURE_MATERIAL_BONUS, 0.99);
        foreach ([self::MATERIAL_RIG_TYPE_ID => [-2.4, 0], self::TIME_RIG_TYPE_ID => [0, -24.0]] as $rigTypeId => [$materialBonus, $timeBonus]) {
            $this->insertAttribute($connection, $rigTypeId, self::ATTRIBUTE_HI_SEC_MODIFIER, 1);
            $this->insertAttribute($connection, $rigTypeId, self::ATTRIBUTE_LOW_SEC_MODIFIER, 1.9);
            $this->insertAttribute($connection, $rigTypeId, self::ATTRIBUTE_NULL_SEC_MODIFIER, 2.1);
            $this->insertAttribute($connection, $rigTypeId, self::ATTRIBUTE_RIG_MATERIAL_BONUS, $materialBonus);
            $this->insertAttribute($connection, $rigTypeId, self::ATTRIBUTE_RIG_TIME_BONUS, $timeBonus);
        }
    }

    private function insertAttribute(Connection $connection, int $typeId, int $attributeId, int|float $value): void
    {
        $connection->insert('sde_dgm_type_attributes', [
            'type_id' => $typeId,
            'attribute_id' => $attributeId,
            'value_int' => \is_int($value) ? $value : null,
            'value_float' => \is_float($value) ? $value : null,
        ]);
    }

    private function seedOneJumpBetweenTheTwoSystems(): void
    {
        foreach ([[self::FAVORITE_SYSTEM_ID, self::ONE_JUMP_SYSTEM_ID], [self::ONE_JUMP_SYSTEM_ID, self::FAVORITE_SYSTEM_ID]] as [$from, $to]) {
            $this->em->persist((new MapSolarSystemJump())
                ->setFromSolarSystemId($from)
                ->setToSolarSystemId($to)
                ->setFromRegionId(self::REGION_ID)
                ->setFromConstellationId(self::CONSTELLATION_ID)
                ->setToRegionId(self::REGION_ID)
                ->setToConstellationId(self::CONSTELLATION_ID));
        }
    }

    private function group(int $groupId, string $name, InvCategory $category): InvGroup
    {
        $group = (new InvGroup())->setGroupId($groupId)->setGroupName($name)->setCategory($category);
        $this->em->persist($group);

        return $group;
    }

    private function type(int $typeId, string $name, InvGroup $group, float $volume): void
    {
        $this->em->persist((new InvType())->setTypeId($typeId)->setTypeName($name)->setGroup($group)->setVolume($volume));
    }
}
