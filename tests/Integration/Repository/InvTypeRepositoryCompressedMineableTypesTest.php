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

#[Group('issue-314')]
final class InvTypeRepositoryCompressedMineableTypesTest extends IntegrationTestCase
{
    private const int ASTEROID_CATEGORY_ID = 25;
    private const int CELESTIAL_CATEGORY_ID = 2;
    private const int COMPRESSED_GAS_GROUP_ID = 4168;
    private const int FIXTURE_CATEGORY_ID = 990_314;
    private const int FIXTURE_ORE_GROUP_ID = 990_314_001;
    private const int FIXTURE_OTHER_GROUP_ID = 990_314_002;
    private const int FIXTURE_CELESTIAL_GROUP_ID = 990_314_003;
    private const int FIXTURE_MARKET_GROUP_ID = 990_314;

    private const int COMPRESSED_ORE = 990_314_101;
    private const int COMPRESSED_GAS = 990_314_102;
    private const int BATCH_COMPRESSED_ORE = 990_314_103;
    private const int UNPUBLISHED_COMPRESSED_ORE = 990_314_104;
    private const int RAW_ORE = 990_314_105;
    private const int COMPRESSED_TYPE_OF_ANOTHER_CATEGORY = 990_314_106;
    private const int COMPRESSED_CELESTIAL_OUTSIDE_COMPRESSED_GAS = 990_314_107;
    private const int COMPRESSED_ORE_NOT_HELD = 990_314_108;
    private const int TYPE_UNKNOWN_TO_SDE = 990_314_199;

    public function testOnlyPublishedCompressedAsteroidsAndCompressedGasesAmongTheGivenTypesAreReturned(): void
    {
        $asteroids = $this->category(self::ASTEROID_CATEGORY_ID, 'Asteroid');
        $celestials = $this->category(self::CELESTIAL_CATEGORY_ID, 'Celestial');
        $fixtureOres = $this->group(self::FIXTURE_ORE_GROUP_ID, 'Fixture Ore 314', $asteroids);
        $compressedGases = $this->group(self::COMPRESSED_GAS_GROUP_ID, 'Compressed Gas', $celestials);
        $fixtureCelestials = $this->group(self::FIXTURE_CELESTIAL_GROUP_ID, 'Fixture Celestial 314', $celestials);
        $fixtureOthers = $this->group(self::FIXTURE_OTHER_GROUP_ID, 'Fixture Other 314', $this->category(self::FIXTURE_CATEGORY_ID, 'Fixture Category 314'));
        $marketGroup = (new InvMarketGroup())->setMarketGroupId(self::FIXTURE_MARKET_GROUP_ID)->setMarketGroupName('Fixture Market 314');
        $this->em->persist($marketGroup);

        $this->type(self::COMPRESSED_ORE, 'Compressed Fixture Ore 314', $fixtureOres, true, $marketGroup);
        $this->type(self::COMPRESSED_GAS, 'Compressed Fixture Gas 314', $compressedGases, true, $marketGroup);
        $this->type(self::BATCH_COMPRESSED_ORE, 'Batch Compressed Fixture Ore 314', $fixtureOres, true, $marketGroup);
        $this->type(self::UNPUBLISHED_COMPRESSED_ORE, 'Compressed Fixture Hidden Ore 314', $fixtureOres, false, $marketGroup);
        $this->type(self::RAW_ORE, 'Fixture Ore 314', $fixtureOres, true, $marketGroup);
        $this->type(self::COMPRESSED_TYPE_OF_ANOTHER_CATEGORY, 'Compressed Fixture Other 314', $fixtureOthers, true, $marketGroup);
        $this->type(self::COMPRESSED_CELESTIAL_OUTSIDE_COMPRESSED_GAS, 'Compressed Fixture Celestial 314', $fixtureCelestials, true, $marketGroup);
        $this->type(self::COMPRESSED_ORE_NOT_HELD, 'Compressed Fixture Unheld Ore 314', $fixtureOres, true, $marketGroup);
        $this->flushAndClear();

        $compressedTypeIds = $this->compressedMineableTypeIdsAmong([
            self::COMPRESSED_ORE,
            self::COMPRESSED_GAS,
            self::BATCH_COMPRESSED_ORE,
            self::UNPUBLISHED_COMPRESSED_ORE,
            self::RAW_ORE,
            self::COMPRESSED_TYPE_OF_ANOTHER_CATEGORY,
            self::COMPRESSED_CELESTIAL_OUTSIDE_COMPRESSED_GAS,
            self::TYPE_UNKNOWN_TO_SDE,
        ]);

        self::assertSame([self::COMPRESSED_ORE, self::COMPRESSED_GAS], $compressedTypeIds);
    }

    public function testNoGivenTypeReturnsNoType(): void
    {
        self::assertSame([], $this->compressedMineableTypeIdsAmong([]));
    }

    private function compressedMineableTypeIdsAmong(array $typeIds): array
    {
        $typeIdsFound = array_map(
            static fn (InvType $type): int => $type->getTypeId(),
            self::getContainer()->get(InvTypeRepository::class)->findCompressedMineableTypes($typeIds),
        );
        sort($typeIdsFound);

        return $typeIdsFound;
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

    private function type(int $typeId, string $typeName, InvGroup $group, bool $published, InvMarketGroup $marketGroup): void
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
