<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry;

use App\Service\Industry\IndustryBlacklistService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(IndustryBlacklistService::class)]
#[Group('issue-445')]
final class IndustryBlacklistServiceResolveGroupIdsTest extends TestCase
{
    public function testTheGroupsOfTheCategoriesFollowTheExplicitGroupsWithoutDuplicate(): void
    {
        $resolved = IndustryBlacklistService::resolveGroupIds([999, 334], ['advanced_components', 'biochem_reactions']);

        self::assertSame([999, 334, 661, 662], $resolved);
    }

    public function testAnUnknownCategoryKeyIsIgnored(): void
    {
        self::assertSame([1136], IndustryBlacklistService::resolveGroupIds([], ['fuel_blocks', 'unknown_category']));
    }

    public function testNothingToResolveGivesAnEmptyList(): void
    {
        self::assertSame([], IndustryBlacklistService::resolveGroupIds([], []));
    }
}
