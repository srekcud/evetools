<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Industry;

use ApiPlatform\Metadata\Get;
use App\Entity\User;
use App\Service\Industry\IndustryBlacklistService;
use App\State\Provider\Industry\BlacklistProvider;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

#[CoversClass(BlacklistProvider::class)]
#[Group('issue-139')]
final class BlacklistProviderTest extends TestCase
{
    private const int BIOCHEMICAL_REACTIONS_FIRST_GROUP_ID = 661;
    private const int FUEL_BLOCKS_GROUP_ID = 1136;
    private const int GROUP_OUTSIDE_EVERY_CATEGORY = 999_999;

    public function testExposesTheGroupsActuallyBlacklistedBesideTheCategoryStates(): void
    {
        $user = (new User())->setIndustryBlacklistGroupIds([self::BIOCHEMICAL_REACTIONS_FIRST_GROUP_ID, self::FUEL_BLOCKS_GROUP_ID, self::GROUP_OUTSIDE_EVERY_CATEGORY]);

        $resource = $this->providerOf($user)->provide(new Get());

        self::assertSame([self::BIOCHEMICAL_REACTIONS_FIRST_GROUP_ID, self::FUEL_BLOCKS_GROUP_ID, self::GROUP_OUTSIDE_EVERY_CATEGORY], $resource->groupIds);
        $states = array_column($resource->categories, null, 'key');
        self::assertTrue($states['fuel_blocks']['blacklisted']);
        self::assertTrue($states['biochem_reactions']['partiallyBlacklisted']);
        self::assertFalse($states['biochem_reactions']['blacklisted']);
        self::assertSame([], $resource->items);
    }

    public function testRejectsAnAnonymousRequest(): void
    {
        $this->expectException(UnauthorizedHttpException::class);

        $this->providerOf(null)->provide(new Get());
    }

    private function providerOf(?User $user): BlacklistProvider
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);

        return new BlacklistProvider($security, new IndustryBlacklistService($this->createStub(EntityManagerInterface::class)));
    }
}
