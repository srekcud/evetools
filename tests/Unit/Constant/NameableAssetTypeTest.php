<?php

declare(strict_types=1);

namespace App\Tests\Unit\Constant;

use App\Constant\NameableAssetType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(NameableAssetType::class)]
#[Group('issue-863')]
final class NameableAssetTypeTest extends TestCase
{
    private const int UNRELATED_GROUP_ID = 25;
    private const int UNRELATED_CATEGORY_ID = 4;

    public static function categoriesAndGroups(): iterable
    {
        yield 'ship category' => [6, 25, true];
        yield 'deployable category' => [22, 361, true];
        yield 'starbase category' => [23, 365, true];
        yield 'structure category' => [65, 1657, true];
        yield 'cargo container group' => [2, 12, true];
        yield 'secure cargo container group' => [2, 340, true];
        yield 'audit log secure container group' => [2, 448, true];
        yield 'freight container group' => [2, 649, true];
        yield 'container group without a category' => [null, 12, true];
        yield 'ship category without a group' => [6, null, true];
        yield 'material category' => [4, 18, false];
        yield 'blueprint category' => [9, 105, false];
        yield 'module category' => [7, 55, false];
        yield 'celestial category outside the container groups' => [2, 7, false];
        yield 'category and group unknown' => [null, null, false];
        yield 'unknown category with an unrelated group' => [null, self::UNRELATED_GROUP_ID, false];
        yield 'unrelated category with an unknown group' => [self::UNRELATED_CATEGORY_ID, null, false];
    }

    #[DataProvider('categoriesAndGroups')]
    public function testOnlyShipsDeployablesStarbasesStructuresAndContainersCanHaveACustomName(
        ?int $categoryId,
        ?int $groupId,
        bool $expected,
    ): void {
        self::assertSame($expected, NameableAssetType::accepts($categoryId, $groupId));
    }
}
