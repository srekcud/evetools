<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Industry\Project;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectProduct;
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
use App\Service\Industry\EsiCostIndexService;
use App\Service\Industry\Project\IndustryProjectService;
use App\Service\JitaMarketService;
use App\Tests\Integration\IntegrationTestCase;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Group;

#[Group('issue-341')]
final class ProjectBomStructureChoiceByTotalCostTest extends IntegrationTestCase
{
    private const int FAVORITE_SYSTEM_ID = 30_999_911;
    private const int ONE_JUMP_SYSTEM_ID = 30_999_912;
    private const int REGION_ID = 10_999_910;
    private const int CONSTELLATION_ID = 20_999_910;

    private const int SHIP_CATEGORY_ID = 999_995_006;
    private const int FRIGATE_GROUP_ID = 999_995_025;
    private const int PLATING_GROUP_ID = 999_995_334;
    private const int ALLOY_GROUP_ID = 999_995_018;
    private const int RIG_GROUP_ID = 999_995_773;
    private const int FRIGATE_TYPE_ID = 999_995_587;
    private const int FRIGATE_BLUEPRINT_TYPE_ID = 999_995_588;
    private const int PLATING_TYPE_ID = 999_995_111;
    private const int PLATING_BLUEPRINT_TYPE_ID = 999_995_112;
    private const int ALLOY_TYPE_ID = 999_995_036;
    private const int FAVORITE_RIG_TYPE_ID = 999_995_501;
    private const int NEIGHBOUR_RIG_TYPE_ID = 999_995_502;
    private const int TAXED_RIG_TYPE_ID = 999_995_503;

    private const string FAVORITE_RIG_NAME = 'Test Basic Small Ship Manufacturing Material Rig Favorite';
    private const string NEIGHBOUR_RIG_NAME = 'Test Basic Small Ship Manufacturing Material Rig Neighbour';
    private const string TAXED_RIG_NAME = 'Test Basic Small Ship Manufacturing Material Rig Taxed';

    private const int RAITARU_TYPE_ID = 35825;
    private const int ATTRIBUTE_STRUCTURE_MATERIAL_BONUS = 2600;
    private const int ATTRIBUTE_HI_SEC_MODIFIER = 2355;
    private const int ATTRIBUTE_LOW_SEC_MODIFIER = 2356;
    private const int ATTRIBUTE_NULL_SEC_MODIFIER = 2357;
    private const int ATTRIBUTE_RIG_MATERIAL_BONUS = 2594;
    private const float RAITARU_MATERIAL_MULTIPLIER = 0.99;
    private const float FAVORITE_RIG_MATERIAL_BONUS = -1.0;
    private const float NEIGHBOUR_RIG_MATERIAL_BONUS = -2.4;
    private const float TAXED_RIG_MATERIAL_BONUS = -3.0;

    private const float FAVORITE_FACILITY_TAX_PERCENT = 1.0;
    private const float NEIGHBOUR_FACILITY_TAX_PERCENT = 0.5;
    private const float TAXED_FACILITY_TAX_PERCENT = 10.0;

    private const int FRIGATE_RUNS = 10;
    private const int PLATINGS_PER_FRIGATE_RUN = 100;
    private const int ALLOYS_PER_PLATING_RUN = 3;
    private const int MAX_PRODUCTION_LIMIT = 5_000;
    private const int FRIGATE_BASE_TIME_PER_RUN_SECONDS = 600;
    private const int PLATING_BASE_TIME_PER_RUN_SECONDS = 100;
    private const float PLATING_UNIT_PRICE = 5_000.0;
    private const float PLATING_ADJUSTED_PRICE = 4_000.0;
    private const float ALLOY_UNIT_PRICE = 20.0;
    private const float ALLOY_ADJUSTED_PRICE = 15.0;
    private const float COST_INDEX = 0.04;
    private const float LOW_MINIMUM_OUTSIDE_SAVING = 100_000.0;
    private const float HIGH_MINIMUM_OUTSIDE_SAVING = 1_000_000.0;

    private const int PLATINGS_AT_THE_FAVORITE_STRUCTURE = 970;
    private const int ALLOYS_FOR_THE_PLATINGS_OF_THE_FAVORITE_STRUCTURE = 2_881;
    private const int PLATINGS_AT_THE_NEIGHBOUR_STRUCTURE = 941;
    private const int ALLOYS_FOR_THE_PLATINGS_OF_THE_NEIGHBOUR_STRUCTURE = 2_795;

    private User $pilot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->replaceMarketAndEsiSources();
        $character = $this->createCharacter(name: 'Total Cost Builder');
        $pilot = $character->getUser();
        \assert($pilot instanceof User);
        $pilot->setMainCharacter($character);
        $this->pilot = $pilot;
        $this->seedFrigateRecipe();
        $this->seedRigsAndRaitaruRoleBonus();
        $this->seedOneJumpBetweenTheTwoSystems();
        $this->em->persist((new IndustryUserSettings())->setUser($pilot)->setFavoriteManufacturingSystemId(self::FAVORITE_SYSTEM_ID));
        $this->storeStructure('Favorite Raitaru', self::FAVORITE_SYSTEM_ID, self::FAVORITE_RIG_NAME, self::FAVORITE_FACILITY_TAX_PERCENT);
        $this->storeStructure('Neighbour Raitaru', self::ONE_JUMP_SYSTEM_ID, self::NEIGHBOUR_RIG_NAME, self::NEIGHBOUR_FACILITY_TAX_PERCENT);
        $this->storeStructure('Taxed Raitaru', self::ONE_JUMP_SYSTEM_ID, self::TAXED_RIG_NAME, self::TAXED_FACILITY_TAX_PERCENT);
        $this->em->flush();
    }

    public function testBomOfAProjectAcceptingAStructureOutsideTheFavoriteSystemUsesTheMaterialBonusOfTheCheaperStructureOfAnotherSystem(): void
    {
        $project = $this->frigateProject(acceptsOutsideFavoriteSystem: true, minimumOutsideSaving: self::LOW_MINIMUM_OUTSIDE_SAVING);

        $this->projectService()->buildBom($project, $this->pilot);

        self::assertSame(
            [self::PLATING_TYPE_ID, 'manufacturing', 'component', self::PLATINGS_AT_THE_NEIGHBOUR_STRUCTURE, self::PLATINGS_AT_THE_NEIGHBOUR_STRUCTURE],
            $this->platingJobLineOf($project),
        );
        self::assertSame(self::ALLOYS_FOR_THE_PLATINGS_OF_THE_NEIGHBOUR_STRUCTURE, $this->requiredQuantityOfMaterial($project, self::ALLOY_TYPE_ID));
    }

    public function testBomOfAProjectRefusingAStructureOutsideTheFavoriteSystemUsesTheStructureOfTheFavoriteSystem(): void
    {
        $project = $this->frigateProject(acceptsOutsideFavoriteSystem: false, minimumOutsideSaving: self::LOW_MINIMUM_OUTSIDE_SAVING);

        $this->projectService()->buildBom($project, $this->pilot);

        self::assertSame(
            [self::PLATING_TYPE_ID, 'manufacturing', 'component', self::PLATINGS_AT_THE_FAVORITE_STRUCTURE, self::PLATINGS_AT_THE_FAVORITE_STRUCTURE],
            $this->platingJobLineOf($project),
        );
        self::assertSame(self::ALLOYS_FOR_THE_PLATINGS_OF_THE_FAVORITE_STRUCTURE, $this->requiredQuantityOfMaterial($project, self::ALLOY_TYPE_ID));
    }

    public function testBomOfAProjectWhoseMinimumOutsideSavingIsAboveTheSavingUsesTheStructureOfTheFavoriteSystem(): void
    {
        $project = $this->frigateProject(acceptsOutsideFavoriteSystem: true, minimumOutsideSaving: self::HIGH_MINIMUM_OUTSIDE_SAVING);

        $this->projectService()->buildBom($project, $this->pilot);

        self::assertSame(
            [self::PLATING_TYPE_ID, 'manufacturing', 'component', self::PLATINGS_AT_THE_FAVORITE_STRUCTURE, self::PLATINGS_AT_THE_FAVORITE_STRUCTURE],
            $this->platingJobLineOf($project),
        );
        self::assertSame(self::ALLOYS_FOR_THE_PLATINGS_OF_THE_FAVORITE_STRUCTURE, $this->requiredQuantityOfMaterial($project, self::ALLOY_TYPE_ID));
    }

    private function projectService(): IndustryProjectService
    {
        return self::getContainer()->get(IndustryProjectService::class);
    }

    private function frigateProject(bool $acceptsOutsideFavoriteSystem, float $minimumOutsideSaving): IndustryProject
    {
        return (new IndustryProject())
            ->setOwner($this->pilot)
            ->setAcceptsOutsideFavoriteSystem($acceptsOutsideFavoriteSystem)
            ->setMinimumOutsideSaving($minimumOutsideSaving)
            ->addItem((new IndustryProjectProduct())
                ->setTypeId(self::FRIGATE_TYPE_ID)
                ->setTypeName('Test Frigate')
                ->setMeLevel(0)
                ->setTeLevel(0)
                ->setRuns(self::FRIGATE_RUNS)
                ->setSortOrder(0));
    }

    private function platingJobLineOf(IndustryProject $project): array
    {
        $platingJobLines = array_values(array_filter(
            $project->getBomItems()->toArray(),
            static fn (IndustryProjectBomLine $line): bool => $line->isJob() && self::PLATING_TYPE_ID === $line->getTypeId() && 'manufacturing' === $line->getActivityType(),
        ));
        self::assertCount(1, $platingJobLines);
        $line = $platingJobLines[0];

        return [$line->getTypeId(), $line->getActivityType(), $line->getJobGroup(), $line->getRuns(), $line->getRequiredQuantity()];
    }

    private function requiredQuantityOfMaterial(IndustryProject $project, int $typeId): int
    {
        foreach ($project->getBomItems() as $line) {
            if (!$line->isJob() && $typeId === $line->getTypeId()) {
                return $line->getRequiredQuantity();
            }
        }

        self::fail(\sprintf('The BOM has no material line for type %d.', $typeId));
    }

    private function storeStructure(string $name, int $solarSystemId, string $rigName, float $facilityTaxPercent): void
    {
        $this->em->persist((new IndustryStructureConfig())
            ->setUser($this->pilot)
            ->setName($name)
            ->setSolarSystemId($solarSystemId)
            ->setSecurityType('nullsec')
            ->setStructureType('raitaru')
            ->setRigs([$rigName])
            ->setFacilityTaxPercent($facilityTaxPercent));
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
        $jitaMarket->method('getCheapestPercentilePrices')->willReturnCallback(
            static fn (array $typeIds): array => array_map(
                static fn (int $typeId): ?float => $sellPrices[$typeId] ?? null,
                array_combine($typeIds, $typeIds),
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
        $platings = $this->group(self::PLATING_GROUP_ID, 'Test Plating', $ships);
        $alloys = $this->group(self::ALLOY_GROUP_ID, 'Test Alloy', $ships);
        $this->group(self::RIG_GROUP_ID, 'Test Rig', $ships);
        $this->type(self::FRIGATE_TYPE_ID, 'Test Frigate', $frigates, 0.1);
        $this->type(self::FRIGATE_BLUEPRINT_TYPE_ID, 'Test Frigate Blueprint', $frigates, 0.01);
        $this->type(self::PLATING_TYPE_ID, 'Test Plating', $platings, 0.01);
        $this->type(self::PLATING_BLUEPRINT_TYPE_ID, 'Test Plating Blueprint', $platings, 0.01);
        $this->type(self::ALLOY_TYPE_ID, 'Test Alloy', $alloys, 0.02);
        $this->em->persist((new IndustryRigCategory())->setGroupId(self::FRIGATE_GROUP_ID)->setCategory('basic_small_ship'));

        $this->manufacturingRecipe(self::FRIGATE_BLUEPRINT_TYPE_ID, self::FRIGATE_TYPE_ID, self::PLATING_TYPE_ID, self::PLATINGS_PER_FRIGATE_RUN, self::FRIGATE_BASE_TIME_PER_RUN_SECONDS);
        $this->manufacturingRecipe(self::PLATING_BLUEPRINT_TYPE_ID, self::PLATING_TYPE_ID, self::ALLOY_TYPE_ID, self::ALLOYS_PER_PLATING_RUN, self::PLATING_BASE_TIME_PER_RUN_SECONDS);
    }

    private function manufacturingRecipe(int $blueprintTypeId, int $productTypeId, int $materialTypeId, int $baseQuantityPerRun, int $baseTimePerRunSeconds): void
    {
        $manufacturing = IndustryActivityType::Manufacturing->value;
        $this->em->persist((new IndustryBlueprint())->setTypeId($blueprintTypeId)->setMaxProductionLimit(self::MAX_PRODUCTION_LIMIT));
        $this->em->persist((new IndustryActivityProduct())->setTypeId($blueprintTypeId)->setActivityId($manufacturing)->setProductTypeId($productTypeId)->setQuantity(1));
        $this->em->persist((new IndustryActivityMaterial())->setTypeId($blueprintTypeId)->setActivityId($manufacturing)->setMaterialTypeId($materialTypeId)->setQuantity($baseQuantityPerRun));
        $this->em->persist((new IndustryActivity())->setTypeId($blueprintTypeId)->setActivityId($manufacturing)->setTime($baseTimePerRunSeconds));
    }

    private function seedRigsAndRaitaruRoleBonus(): void
    {
        $rigGroup = $this->em->find(InvGroup::class, self::RIG_GROUP_ID);
        \assert($rigGroup instanceof InvGroup);
        $rigs = [
            self::FAVORITE_RIG_TYPE_ID => [self::FAVORITE_RIG_NAME, self::FAVORITE_RIG_MATERIAL_BONUS],
            self::NEIGHBOUR_RIG_TYPE_ID => [self::NEIGHBOUR_RIG_NAME, self::NEIGHBOUR_RIG_MATERIAL_BONUS],
            self::TAXED_RIG_TYPE_ID => [self::TAXED_RIG_NAME, self::TAXED_RIG_MATERIAL_BONUS],
        ];
        foreach ($rigs as $rigTypeId => [$rigName]) {
            $this->type($rigTypeId, $rigName, $rigGroup, 0.0);
        }
        $this->em->flush();

        $connection = self::getContainer()->get(Connection::class);
        $this->insertAttribute($connection, self::RAITARU_TYPE_ID, self::ATTRIBUTE_STRUCTURE_MATERIAL_BONUS, self::RAITARU_MATERIAL_MULTIPLIER);
        foreach ($rigs as $rigTypeId => [, $materialBonus]) {
            $this->insertAttribute($connection, $rigTypeId, self::ATTRIBUTE_HI_SEC_MODIFIER, 1);
            $this->insertAttribute($connection, $rigTypeId, self::ATTRIBUTE_LOW_SEC_MODIFIER, 1.9);
            $this->insertAttribute($connection, $rigTypeId, self::ATTRIBUTE_NULL_SEC_MODIFIER, 2.1);
            $this->insertAttribute($connection, $rigTypeId, self::ATTRIBUTE_RIG_MATERIAL_BONUS, $materialBonus);
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
