<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry;

use App\Entity\User;
use App\Service\Industry\IndustryBlacklistService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(IndustryBlacklistService::class)]
#[Group('issue-139')]
final class IndustryBlacklistServiceCategoriesTest extends TestCase
{
    private const int BIOCHEMICAL_REACTIONS_FIRST_GROUP_ID = 661;
    private const int BIOCHEMICAL_REACTIONS_SECOND_GROUP_ID = 662;
    private const int FUEL_BLOCKS_GROUP_ID = 1136;

    private IndustryBlacklistService $service;

    protected function setUp(): void
    {
        $this->service = new IndustryBlacklistService($this->createStub(EntityManagerInterface::class));
    }

    public function testCategoryWithEveryGroupBlacklistedIsBlacklistedAndNotPartial(): void
    {
        $user = (new User())->setIndustryBlacklistGroupIds([self::BIOCHEMICAL_REACTIONS_FIRST_GROUP_ID, self::BIOCHEMICAL_REACTIONS_SECOND_GROUP_ID]);

        self::assertSame(['blacklisted' => true, 'partiallyBlacklisted' => false], $this->stateOf('biochem_reactions', $user));
    }

    public function testCategoryWithOnlyOneOfItsGroupsBlacklistedIsPartialAndNotBlacklisted(): void
    {
        $user = (new User())->setIndustryBlacklistGroupIds([self::BIOCHEMICAL_REACTIONS_FIRST_GROUP_ID]);

        self::assertSame(['blacklisted' => false, 'partiallyBlacklisted' => true], $this->stateOf('biochem_reactions', $user));
    }

    public function testCategoryWithNoneOfItsGroupsBlacklistedIsNeitherBlacklistedNorPartial(): void
    {
        $user = (new User())->setIndustryBlacklistGroupIds([self::FUEL_BLOCKS_GROUP_ID]);

        self::assertSame(['blacklisted' => false, 'partiallyBlacklisted' => false], $this->stateOf('biochem_reactions', $user));
    }

    public function testCategoryOfASingleGroupIsNeverPartial(): void
    {
        $blacklistedUser = (new User())->setIndustryBlacklistGroupIds([self::FUEL_BLOCKS_GROUP_ID]);
        $otherUser = new User();

        self::assertSame(['blacklisted' => true, 'partiallyBlacklisted' => false], $this->stateOf('fuel_blocks', $blacklistedUser));
        self::assertSame(['blacklisted' => false, 'partiallyBlacklisted' => false], $this->stateOf('fuel_blocks', $otherUser));
    }

    public function testCategoriesKeepTheirKeyLabelAndGroupIds(): void
    {
        $categories = $this->service->getCategories(new User());

        $biochemical = array_values(array_filter($categories, static fn (array $category): bool => 'biochem_reactions' === $category['key']))[0];
        self::assertSame('Biochemical Reactions', $biochemical['label']);
        self::assertSame([self::BIOCHEMICAL_REACTIONS_FIRST_GROUP_ID, self::BIOCHEMICAL_REACTIONS_SECOND_GROUP_ID], $biochemical['groupIds']);
        self::assertCount(count(IndustryBlacklistService::BLACKLIST_CATEGORIES), $categories);
    }

    /**
     * @return array{blacklisted: bool, partiallyBlacklisted: bool}
     */
    private function stateOf(string $categoryKey, User $user): array
    {
        foreach ($this->service->getCategories($user) as $category) {
            if ($categoryKey === $category['key']) {
                return ['blacklisted' => $category['blacklisted'], 'partiallyBlacklisted' => $category['partiallyBlacklisted']];
            }
        }

        throw new \LogicException(\sprintf('Category %s is unknown.', $categoryKey));
    }
}
