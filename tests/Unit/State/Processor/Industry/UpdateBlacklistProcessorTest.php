<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\Industry;

use ApiPlatform\Metadata\Put;
use App\ApiResource\Input\Industry\UpdateBlacklistInput;
use App\Entity\User;
use App\Service\Industry\IndustryBlacklistService;
use App\State\Processor\Industry\UpdateBlacklistProcessor;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;

#[CoversClass(UpdateBlacklistProcessor::class)]
#[Group('issue-139')]
final class UpdateBlacklistProcessorTest extends TestCase
{
    private const int BIOCHEMICAL_REACTIONS_FIRST_GROUP_ID = 661;
    private const int BIOCHEMICAL_REACTIONS_SECOND_GROUP_ID = 662;
    private const int GROUP_OUTSIDE_EVERY_CATEGORY = 999_999;

    public function testAnswersWithTheSavedGroupsAndTheStateOfEveryCategory(): void
    {
        $user = new User();
        $input = new UpdateBlacklistInput();
        $input->groupIds = [self::BIOCHEMICAL_REACTIONS_FIRST_GROUP_ID, self::GROUP_OUTSIDE_EVERY_CATEGORY];

        $resource = $this->processorOf($user)->process($input, new Put());

        self::assertSame([self::BIOCHEMICAL_REACTIONS_FIRST_GROUP_ID, self::GROUP_OUTSIDE_EVERY_CATEGORY], $resource->groupIds);
        self::assertSame([self::BIOCHEMICAL_REACTIONS_FIRST_GROUP_ID, self::GROUP_OUTSIDE_EVERY_CATEGORY], $user->getIndustryBlacklistGroupIds());
        $states = array_column($resource->categories, null, 'key');
        self::assertTrue($states['biochem_reactions']['partiallyBlacklisted']);
        self::assertFalse($states['biochem_reactions']['blacklisted']);
    }

    public function testCompletingAPartialCategoryMakesItBlacklisted(): void
    {
        $user = (new User())->setIndustryBlacklistGroupIds([self::BIOCHEMICAL_REACTIONS_FIRST_GROUP_ID]);
        $input = new UpdateBlacklistInput();
        $input->groupIds = [self::BIOCHEMICAL_REACTIONS_FIRST_GROUP_ID, self::BIOCHEMICAL_REACTIONS_SECOND_GROUP_ID];

        $resource = $this->processorOf($user)->process($input, new Put());

        $states = array_column($resource->categories, null, 'key');
        self::assertTrue($states['biochem_reactions']['blacklisted']);
        self::assertFalse($states['biochem_reactions']['partiallyBlacklisted']);
    }

    #[Group('issue-806')]
    public function testExcludeInventionIsSavedOnTheUserAndExposedAndAnAbsentValueLeavesItUnchanged(): void
    {
        $user = new User();
        $excluding = new UpdateBlacklistInput();
        $excluding->excludeInvention = true;
        $groupsOnly = new UpdateBlacklistInput();
        $groupsOnly->groupIds = [self::GROUP_OUTSIDE_EVERY_CATEGORY];

        $afterExcluding = $this->processorOf($user)->process($excluding, new Put());
        $afterGroupsOnly = $this->processorOf($user)->process($groupsOnly, new Put());

        self::assertTrue($afterExcluding->excludeInvention);
        self::assertTrue($afterGroupsOnly->excludeInvention);
        self::assertTrue($user->isIndustryExcludeInvention());
    }

    #[Group('issue-806')]
    public function testExcludeInventionFalseClearsTheSettingOfTheUser(): void
    {
        $user = (new User())->setIndustryExcludeInvention(true);
        $input = new UpdateBlacklistInput();
        $input->excludeInvention = false;

        $resource = $this->processorOf($user)->process($input, new Put());

        self::assertFalse($resource->excludeInvention);
        self::assertFalse($user->isIndustryExcludeInvention());
    }

    private function processorOf(User $user): UpdateBlacklistProcessor
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);
        $entityManager = $this->createStub(EntityManagerInterface::class);

        return new UpdateBlacklistProcessor($security, new IndustryBlacklistService($entityManager), $entityManager);
    }
}
