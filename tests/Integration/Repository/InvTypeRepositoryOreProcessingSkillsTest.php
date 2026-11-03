<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\Sde\DgmTypeAttribute;
use App\Entity\Sde\InvCategory;
use App\Entity\Sde\InvGroup;
use App\Entity\Sde\InvMarketGroup;
use App\Entity\Sde\InvType;
use App\Repository\Sde\InvTypeRepository;
use App\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('issue-269')]
final class InvTypeRepositoryOreProcessingSkillsTest extends IntegrationTestCase
{
    private const int REPROCESSING_SKILL_TYPE_ATTRIBUTE_ID = 790;
    private const int ASTEROID_CATEGORY_ID = 25;
    private const int CELESTIAL_CATEGORY_ID = 2;
    private const int HARVESTABLE_CLOUD_GROUP_ID = 711;
    private const int FIXTURE_CATEGORY_ID = 990_269;
    private const int FIXTURE_ORE_GROUP_ID = 990_269_001;
    private const int FIXTURE_SKILL_GROUP_ID = 990_269_002;
    private const int FIXTURE_MARKET_GROUP_ID = 990_269;

    private const int PUBLISHED_ORE = 990_269_101;
    private const int SECOND_PUBLISHED_ORE = 990_269_102;
    private const int PUBLISHED_GAS = 990_269_103;
    private const int UNPUBLISHED_ORE = 990_269_104;
    private const int TYPE_OF_ANOTHER_CATEGORY = 990_269_105;
    private const int ORE_WITHOUT_REPROCESSING_SKILL = 990_269_106;

    private const int ZETA_PROCESSING = 990_269_201;
    private const int ALPHA_PROCESSING = 990_269_202;
    private const int BETA_PROCESSING = 990_269_203;
    private const int GAMMA_PROCESSING = 990_269_204;
    private const array FIXTURE_SKILL_TYPE_IDS = [self::ZETA_PROCESSING, self::ALPHA_PROCESSING, self::BETA_PROCESSING, self::GAMMA_PROCESSING];

    public function testListsOnceEachSkillThatTheSdeAssignsToAnOreOfTheTableSortedByName(): void
    {
        $asteroids = $this->category(self::ASTEROID_CATEGORY_ID, 'Asteroid');
        $celestials = $this->category(self::CELESTIAL_CATEGORY_ID, 'Celestial');
        $fixtureCategory = $this->category(self::FIXTURE_CATEGORY_ID, 'Fixture Category 269');
        $harvestableClouds = $this->group(self::HARVESTABLE_CLOUD_GROUP_ID, 'Harvestable Cloud', $celestials);
        $fixtureOres = $this->group(self::FIXTURE_ORE_GROUP_ID, 'Fixture Ore 269', $asteroids);
        $fixtureSkills = $this->group(self::FIXTURE_SKILL_GROUP_ID, 'Fixture Skill 269', $fixtureCategory);
        $marketGroup = (new InvMarketGroup())->setMarketGroupId(self::FIXTURE_MARKET_GROUP_ID)->setMarketGroupName('Fixture Market 269');
        $this->em->persist($marketGroup);

        $this->type(self::ZETA_PROCESSING, 'Fixture Zeta Processing 269', $fixtureSkills, true, $marketGroup);
        $this->type(self::ALPHA_PROCESSING, 'Fixture Alpha Processing 269', $fixtureSkills, true, $marketGroup);
        $this->type(self::BETA_PROCESSING, 'Fixture Beta Processing 269', $fixtureSkills, true, $marketGroup);
        $this->type(self::GAMMA_PROCESSING, 'Fixture Gamma Processing 269', $fixtureSkills, true, $marketGroup);

        $this->type(self::PUBLISHED_ORE, 'Fixture Ore A 269', $fixtureOres, true, $marketGroup);
        $this->type(self::SECOND_PUBLISHED_ORE, 'Fixture Ore B 269', $fixtureOres, true, $marketGroup);
        $this->type(self::PUBLISHED_GAS, 'Fixture Gas C 269', $harvestableClouds, true, $marketGroup);
        $this->type(self::UNPUBLISHED_ORE, 'Fixture Ore D 269', $fixtureOres, false, $marketGroup);
        $this->type(self::TYPE_OF_ANOTHER_CATEGORY, 'Fixture Other E 269', $fixtureSkills, true, $marketGroup);
        $this->type(self::ORE_WITHOUT_REPROCESSING_SKILL, 'Fixture Ore F 269', $fixtureOres, true, $marketGroup);

        $this->reprocessingSkillOf(self::PUBLISHED_ORE, self::ZETA_PROCESSING);
        $this->reprocessingSkillOf(self::SECOND_PUBLISHED_ORE, self::ZETA_PROCESSING);
        $this->reprocessingSkillOf(self::PUBLISHED_GAS, self::ALPHA_PROCESSING);
        $this->reprocessingSkillOf(self::UNPUBLISHED_ORE, self::BETA_PROCESSING);
        $this->reprocessingSkillOf(self::TYPE_OF_ANOTHER_CATEGORY, self::GAMMA_PROCESSING);
        $this->flushAndClear();

        $fixtureSkills = array_values(array_filter(
            self::getContainer()->get(InvTypeRepository::class)->findOreProcessingSkills(),
            static fn (array $skill): bool => in_array($skill['typeId'], self::FIXTURE_SKILL_TYPE_IDS, true),
        ));

        self::assertSame(
            [
                ['typeId' => self::ALPHA_PROCESSING, 'typeName' => 'Fixture Alpha Processing 269'],
                ['typeId' => self::ZETA_PROCESSING, 'typeName' => 'Fixture Zeta Processing 269'],
            ],
            $fixtureSkills,
        );
    }

    private function category(int $categoryId, string $categoryName): InvCategory
    {
        $category = $this->em->find(InvCategory::class, $categoryId);
        if ($category === null) {
            $category = (new InvCategory())->setCategoryId($categoryId)->setCategoryName($categoryName)->setPublished(true);
            $this->em->persist($category);
        }

        return $category;
    }

    private function group(int $groupId, string $groupName, InvCategory $category): InvGroup
    {
        $group = $this->em->find(InvGroup::class, $groupId);
        if ($group === null) {
            $group = (new InvGroup())->setGroupId($groupId)->setGroupName($groupName)->setCategory($category)->setPublished(true);
            $this->em->persist($group);
        }

        return $group;
    }

    private function type(int $typeId, string $typeName, InvGroup $group, bool $published, ?InvMarketGroup $marketGroup): void
    {
        $this->em->persist(
            (new InvType())
                ->setTypeId($typeId)
                ->setTypeName($typeName)
                ->setGroup($group)
                ->setPublished($published)
                ->setMarketGroup($marketGroup)
                ->setVolume(0.1)
                ->setPortionSize(100),
        );
    }

    private function reprocessingSkillOf(int $oreTypeId, int $skillTypeId): void
    {
        $this->em->persist(
            (new DgmTypeAttribute())
                ->setTypeId($oreTypeId)
                ->setAttributeId(self::REPROCESSING_SKILL_TYPE_ATTRIBUTE_ID)
                ->setValueInt($skillTypeId),
        );
    }
}
