<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Industry;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectStep;
use App\Entity\IndustryRigCategory;
use App\Entity\IndustryStructureConfig;
use App\Entity\IndustryUserSettings;
use App\Entity\Sde\IndustryActivityProduct;
use App\Entity\Sde\InvCategory;
use App\Entity\Sde\InvGroup;
use App\Entity\Sde\InvType;
use App\Entity\User;
use App\Enum\IndustryActivityType;
use App\Service\Industry\IndustryCalculationService;
use App\State\Provider\Industry\IndustryResourceMapper;
use App\Tests\Integration\IntegrationTestCase;

/**
 * Issue #92, rule D3b (docs/specs/industry-engine.md): for a step without an assigned structure,
 * the favorite system only offers the structures whose type suits the activity (no refinery for
 * manufacturing, only refineries for reactions). Among equal bonuses, the most recently configured
 * structure wins. When the favorite system has no suitable structure, the best of all structures
 * is taken and a warning is exposed.
 *
 * Products carry a rig category (seeded SDE rows) so the category path of the choice is exercised.
 */
final class FavoriteSystemStructureChoiceTest extends IntegrationTestCase
{
    private const int FAVORITE_SYSTEM_ID = 30_000_142;
    private const int OTHER_SYSTEM_ID = 30_002_187;

    private const int SHIP_CATEGORY_ID = 999_990_006;
    private const int FRIGATE_GROUP_ID = 999_990_025;
    private const int FRIGATE_TYPE_ID = 999_990_587;

    private const int REACTION_CATEGORY_ID = 999_990_024;
    private const int COMPOSITE_FORMULA_GROUP_ID = 999_991_888;
    private const int COMPOSITE_FORMULA_TYPE_ID = 999_991_001;
    private const int COMPOSITE_GROUP_ID = 999_990_429;
    private const int COMPOSITE_TYPE_ID = 999_991_002;

    private const string WARNING_KEY = 'favoriteSystemWithoutSuitableStructure';

    private User $pilot;

    protected function setUp(): void
    {
        parent::setUp();
        $character = $this->createCharacter(name: 'Industry Pilot');
        $pilot = $character->getUser();
        \assert($pilot instanceof User);
        $pilot->setMainCharacter($character);
        $this->pilot = $pilot;
        $this->seedFrigateWithRigCategory();
        $this->seedCompositeReactionWithRigCategory();
        $this->em->flush();
    }

    public function testManufacturingStepSkipsTheRefineryOfTheFavoriteSystemForTheBestEngineeringComplexElsewhere(): void
    {
        $this->storeStructure('Favorite Athanor', 'athanor', self::FAVORITE_SYSTEM_ID);
        $this->storeStructure('Remote Raitaru', 'raitaru', self::OTHER_SYSTEM_ID);
        $this->setFavoriteSystems(manufacturing: self::FAVORITE_SYSTEM_ID);

        $bonus = $this->structureBonusFor($this->stepWithoutStructure(self::FRIGATE_TYPE_ID, 'manufacturing'));

        self::assertSame('Remote Raitaru', $bonus['name']);
        self::assertSame(['total' => 1.0, 'base' => 1.0, 'rig' => 0.0], $bonus['materialBonus']);
        self::assertSame(15.0, $bonus['timeBonus']);
        self::assertArrayHasKey(self::WARNING_KEY, $bonus);
        self::assertTrue($bonus[self::WARNING_KEY]);
    }

    public function testReactionStepSkipsTheEngineeringComplexOfTheFavoriteSystemForTheBestRefineryElsewhere(): void
    {
        $this->storeStructure('Favorite Raitaru', 'raitaru', self::FAVORITE_SYSTEM_ID);
        $this->storeStructure('Remote Tatara', 'tatara', self::OTHER_SYSTEM_ID, ['Standup M-Set Composite Reactor Material Efficiency II']);
        $this->setFavoriteSystems(reaction: self::FAVORITE_SYSTEM_ID);

        $bonus = $this->structureBonusFor($this->stepWithoutStructure(self::COMPOSITE_TYPE_ID, 'reaction'));

        self::assertSame('Remote Tatara', $bonus['name']);
        // 2.4 % rig bonus x 1.1 nullsec reaction multiplier
        self::assertSame(['total' => 2.64, 'base' => 0.0, 'rig' => 2.64], $bonus['materialBonus']);
        self::assertSame(25.0, $bonus['timeBonus']);
        self::assertArrayHasKey(self::WARNING_KEY, $bonus);
        self::assertTrue($bonus[self::WARNING_KEY]);
    }

    public function testAmongEqualStructuresOfTheFavoriteSystemTheMostRecentlyConfiguredWins(): void
    {
        $alpha = $this->storeStructure('Alpha Raitaru', 'raitaru', self::FAVORITE_SYSTEM_ID);
        $bravo = $this->storeStructure('Bravo Raitaru', 'raitaru', self::FAVORITE_SYSTEM_ID);
        $this->setFavoriteSystems(manufacturing: self::FAVORITE_SYSTEM_ID);
        $this->configuredAt($alpha, '2026-10-01 12:00:00');
        $this->configuredAt($bravo, '2026-10-05 12:00:00');

        $bonus = $this->structureBonusFor($this->stepWithoutStructure(self::FRIGATE_TYPE_ID, 'manufacturing'));

        self::assertSame('Bravo Raitaru', $bonus['name']);
        self::assertSame(['total' => 1.0, 'base' => 1.0, 'rig' => 0.0], $bonus['materialBonus']);
        self::assertSame(15.0, $bonus['timeBonus']);
        self::assertArrayHasKey(self::WARNING_KEY, $bonus);
        self::assertFalse($bonus[self::WARNING_KEY]);
    }

    /** Guard (#77): a suitable structure in the favorite system still beats a better one elsewhere. */
    public function testSuitableStructureOfTheFavoriteSystemBeatsABetterOneElsewhere(): void
    {
        $this->storeStructure('Favorite Athanor', 'athanor', self::FAVORITE_SYSTEM_ID);
        $this->storeStructure('Favorite Raitaru', 'raitaru', self::FAVORITE_SYSTEM_ID);
        $this->storeStructure('Remote Sotiyo', 'sotiyo', self::OTHER_SYSTEM_ID, ['Standup XL-Set Ship Manufacturing Efficiency II']);
        $this->setFavoriteSystems(manufacturing: self::FAVORITE_SYSTEM_ID);

        $bonus = $this->structureBonusFor($this->stepWithoutStructure(self::FRIGATE_TYPE_ID, 'manufacturing'));

        self::assertSame('Favorite Raitaru', $bonus['name']);
        self::assertSame(['total' => 1.0, 'base' => 1.0, 'rig' => 0.0], $bonus['materialBonus']);
        self::assertSame(15.0, $bonus['timeBonus']);
    }

    public function testStepResourceExposesTheWarningWhenTheFavoriteSystemHasNoSuitableStructure(): void
    {
        $this->storeStructure('Favorite Athanor', 'athanor', self::FAVORITE_SYSTEM_ID);
        $this->storeStructure('Remote Raitaru', 'raitaru', self::OTHER_SYSTEM_ID);
        $this->setFavoriteSystems(manufacturing: self::FAVORITE_SYSTEM_ID);

        $resource = self::getContainer()->get(IndustryResourceMapper::class)
            ->stepToResource($this->stepWithoutStructure(self::FRIGATE_TYPE_ID, 'manufacturing'));

        self::assertSame('Remote Raitaru', $resource->structureConfigName);
        self::assertTrue(property_exists($resource, self::WARNING_KEY), 'ProjectStepResource must expose the warning');
        self::assertTrue($resource->{self::WARNING_KEY});
    }

    public function testStepResourceHasNoWarningWhenTheFavoriteSystemHasASuitableStructure(): void
    {
        $this->storeStructure('Favorite Raitaru', 'raitaru', self::FAVORITE_SYSTEM_ID);
        $this->setFavoriteSystems(manufacturing: self::FAVORITE_SYSTEM_ID);

        $resource = self::getContainer()->get(IndustryResourceMapper::class)
            ->stepToResource($this->stepWithoutStructure(self::FRIGATE_TYPE_ID, 'manufacturing'));

        self::assertSame('Favorite Raitaru', $resource->structureConfigName);
        self::assertTrue(property_exists($resource, self::WARNING_KEY), 'ProjectStepResource must expose the warning');
        self::assertFalse($resource->{self::WARNING_KEY});
    }

    /** @return array<string, mixed> */
    private function structureBonusFor(IndustryProjectStep $step): array
    {
        return self::getContainer()->get(IndustryCalculationService::class)->getStructureBonusForStep($step);
    }

    /** @param string[] $rigs */
    private function storeStructure(string $name, string $structureType, int $solarSystemId, array $rigs = []): IndustryStructureConfig
    {
        $structure = (new IndustryStructureConfig())
            ->setUser($this->pilot)
            ->setName($name)
            ->setSolarSystemId($solarSystemId)
            ->setSecurityType('nullsec')
            ->setStructureType($structureType)
            ->setRigs($rigs);
        $this->em->persist($structure);
        $this->em->flush();

        return $structure;
    }

    /** created_at is stored to the second: set it in the database, then reload everything. */
    private function configuredAt(IndustryStructureConfig $structure, string $createdAt): void
    {
        $this->em->getConnection()->executeStatement(
            'UPDATE industry_structure_configs SET created_at = :createdAt WHERE id = :id',
            ['createdAt' => $createdAt, 'id' => $structure->getId()?->toRfc4122()],
        );
        $pilotId = $this->pilot->getId();
        $this->em->clear();
        $pilot = $this->em->find(User::class, $pilotId);
        \assert($pilot instanceof User);
        $this->pilot = $pilot;
    }

    private function setFavoriteSystems(?int $manufacturing = null, ?int $reaction = null): void
    {
        $settings = (new IndustryUserSettings())
            ->setUser($this->pilot)
            ->setFavoriteManufacturingSystemId($manufacturing)
            ->setFavoriteReactionSystemId($reaction);
        $this->em->persist($settings);
        $this->em->flush();
    }

    private function stepWithoutStructure(int $productTypeId, string $activityType): IndustryProjectStep
    {
        $project = (new IndustryProject())
            ->setUser($this->pilot)
            ->setProductTypeId($productTypeId)
            ->setRuns(10);

        return (new IndustryProjectStep())
            ->setProject($project)
            ->setProductTypeId($productTypeId)
            ->setBlueprintTypeId($productTypeId)
            ->setActivityType($activityType)
            ->setRuns(10)
            ->setQuantity(10)
            ->setDepth(0);
    }

    private function seedFrigateWithRigCategory(): void
    {
        $ships = $this->category(self::SHIP_CATEGORY_ID, 'Ship');
        $frigates = $this->group(self::FRIGATE_GROUP_ID, 'Frigate', $ships);
        $this->type(self::FRIGATE_TYPE_ID, 'Rifter', $frigates);
        $this->rigCategory(self::FRIGATE_GROUP_ID, 'basic_small_ship');
    }

    /** Reactions take the rig category of the formula's group, not the product's group. */
    private function seedCompositeReactionWithRigCategory(): void
    {
        $reactions = $this->category(self::REACTION_CATEGORY_ID, 'Reaction');
        $formulas = $this->group(self::COMPOSITE_FORMULA_GROUP_ID, 'Composite Reaction Formulas', $reactions);
        $composites = $this->group(self::COMPOSITE_GROUP_ID, 'Composite', $reactions);
        $this->type(self::COMPOSITE_FORMULA_TYPE_ID, 'Titanium Carbide Reaction Formula', $formulas);
        $this->type(self::COMPOSITE_TYPE_ID, 'Titanium Carbide', $composites);
        $this->rigCategory(self::COMPOSITE_FORMULA_GROUP_ID, 'composite_reaction');
        $this->em->persist((new IndustryActivityProduct())
            ->setTypeId(self::COMPOSITE_FORMULA_TYPE_ID)
            ->setActivityId(IndustryActivityType::Reaction->value)
            ->setProductTypeId(self::COMPOSITE_TYPE_ID)
            ->setQuantity(10_000));
    }

    private function category(int $categoryId, string $name): InvCategory
    {
        $category = (new InvCategory())->setCategoryId($categoryId)->setCategoryName($name);
        $this->em->persist($category);

        return $category;
    }

    private function group(int $groupId, string $name, InvCategory $category): InvGroup
    {
        $group = (new InvGroup())->setGroupId($groupId)->setGroupName($name)->setCategory($category);
        $this->em->persist($group);

        return $group;
    }

    private function type(int $typeId, string $name, InvGroup $group): void
    {
        $this->em->persist((new InvType())->setTypeId($typeId)->setTypeName($name)->setGroup($group));
    }

    private function rigCategory(int $groupId, string $category): void
    {
        $this->em->persist((new IndustryRigCategory())->setGroupId($groupId)->setCategory($category));
    }
}
