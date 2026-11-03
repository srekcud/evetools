<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\Sde\InvCategory;
use App\Entity\Sde\InvGroup;
use App\Entity\Sde\InvMarketGroup;
use App\Entity\Sde\InvType;
use App\Repository\Sde\InvTypeRepository;
use App\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('issue-268')]
final class InvTypeRepositoryMineableCandidatesTest extends IntegrationTestCase
{
    private const int ASTEROID_CATEGORY_ID = 25;
    private const int CELESTIAL_CATEGORY_ID = 2;
    private const int HARVESTABLE_CLOUD_GROUP_ID = 711;
    private const int FIXTURE_CATEGORY_ID = 990_268;
    private const int FIXTURE_ORE_GROUP_ID = 990_268_001;
    private const int FIXTURE_CELESTIAL_GROUP_ID = 990_268_002;
    private const int FIXTURE_OTHER_GROUP_ID = 990_268_003;
    private const int FIXTURE_MARKET_GROUP_ID = 990_268;

    private const int PUBLISHED_ORE = 990_268_101;
    private const int PUBLISHED_GAS = 990_268_102;
    private const int UNPUBLISHED_ORE = 990_268_103;
    private const int ORE_WITHOUT_MARKET_GROUP = 990_268_104;
    private const int CELESTIAL_OUTSIDE_HARVESTABLE_CLOUDS = 990_268_105;
    private const int TYPE_OF_ANOTHER_CATEGORY = 990_268_106;
    private const array FIXTURE_TYPE_IDS = [
        self::PUBLISHED_ORE,
        self::PUBLISHED_GAS,
        self::UNPUBLISHED_ORE,
        self::ORE_WITHOUT_MARKET_GROUP,
        self::CELESTIAL_OUTSIDE_HARVESTABLE_CLOUDS,
        self::TYPE_OF_ANOTHER_CATEGORY,
    ];

    public function testMineableCandidatesArePublishedMarketTypesOfTheAsteroidCategoryOrOfHarvestableClouds(): void
    {
        $asteroids = $this->category(self::ASTEROID_CATEGORY_ID, 'Asteroid');
        $celestials = $this->category(self::CELESTIAL_CATEGORY_ID, 'Celestial');
        $harvestableClouds = $this->group(self::HARVESTABLE_CLOUD_GROUP_ID, 'Harvestable Cloud', $celestials);
        $fixtureOres = $this->group(self::FIXTURE_ORE_GROUP_ID, 'Fixture Ore 268', $asteroids);
        $fixtureCelestials = $this->group(self::FIXTURE_CELESTIAL_GROUP_ID, 'Fixture Celestial 268', $celestials);
        $fixtureOthers = $this->group(self::FIXTURE_OTHER_GROUP_ID, 'Fixture Other 268', $this->category(self::FIXTURE_CATEGORY_ID, 'Fixture Category 268'));
        $marketGroup = (new InvMarketGroup())->setMarketGroupId(self::FIXTURE_MARKET_GROUP_ID)->setMarketGroupName('Fixture Market 268');
        $this->em->persist($marketGroup);

        $this->type(self::PUBLISHED_ORE, 'Fixture Ore A', $fixtureOres, true, $marketGroup);
        $this->type(self::PUBLISHED_GAS, 'Fixture Gas B', $harvestableClouds, true, $marketGroup);
        $this->type(self::UNPUBLISHED_ORE, 'Fixture Ore C', $fixtureOres, false, $marketGroup);
        $this->type(self::ORE_WITHOUT_MARKET_GROUP, 'Fixture Ore D', $fixtureOres, true, null);
        $this->type(self::CELESTIAL_OUTSIDE_HARVESTABLE_CLOUDS, 'Fixture Celestial E', $fixtureCelestials, true, $marketGroup);
        $this->type(self::TYPE_OF_ANOTHER_CATEGORY, 'Fixture Other F', $fixtureOthers, true, $marketGroup);
        $this->flushAndClear();

        $candidateTypeIds = array_map(
            static fn (InvType $type): int => $type->getTypeId(),
            self::getContainer()->get(InvTypeRepository::class)->findMineableCandidates(),
        );
        $fixtureCandidateTypeIds = array_values(array_intersect($candidateTypeIds, self::FIXTURE_TYPE_IDS));
        sort($fixtureCandidateTypeIds);

        self::assertSame([self::PUBLISHED_ORE, self::PUBLISHED_GAS], $fixtureCandidateTypeIds);
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
}
