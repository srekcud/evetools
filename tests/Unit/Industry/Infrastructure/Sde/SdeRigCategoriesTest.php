<?php

declare(strict_types=1);

namespace App\Tests\Unit\Industry\Infrastructure\Sde;

use App\Entity\Sde\IndustryActivityProduct;
use App\Entity\Sde\InvGroup;
use App\Entity\Sde\InvType;
use App\Industry\Domain\ActivityKind;
use App\Industry\Infrastructure\Sde\SdeRigCategories;
use App\Repository\IndustryRigCategoryRepository;
use App\Repository\Sde\IndustryActivityProductRepository;
use App\Repository\Sde\InvTypeRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Adapter of the RigCategories port (spec R4, D10): the IndustryRigCategory of a job product is the category of the
 * group of the product in manufacturing, of the group of its reaction formula in reaction (rule of
 * IndustryBonusService::getCategoryForProduct()). null = no rig targets the group: the role bonus only, a game rule,
 * not a default. The group → category map is read once, on the first lookup.
 *
 * Constructor: (IndustryRigCategoryRepository, IndustryActivityProductRepository, InvTypeRepository).
 * Groups and typeIds: SDE of the dev database on 2026-10-22. The categories of the formula groups are synthetic: the
 * dev table industry_rig_categories maps the reaction categories to groups 436 / 484 / 661 / 662 / 977, not to the
 * formula groups 1888-1890.
 */
#[CoversClass(SdeRigCategories::class)]
final class SdeRigCategoriesTest extends TestCase
{
    private const int HAIL_L = 12779;
    private const int ADVANCED_AUTOCANNON_AMMO_GROUP_ID = 372;
    private const int RAM_AMMUNITION_TECH = 11476;
    private const int TOOL_GROUP_ID = 332;
    private const int FERNITE_CARBIDE = 16673;
    private const int COMPOSITE_GROUP_ID = 429;
    private const int FERNITE_CARBIDE_REACTION_FORMULA = 46206;
    private const int COMPOSITE_REACTION_FORMULAS_GROUP_ID = 1888;

    private const int ACTIVITY_REACTION = 11;

    /**
     * Group 429 (the product group of Fernite Carbide) carries a decoy category: a reaction never reads it.
     */
    private const array GROUP_CATEGORIES = [
        self::ADVANCED_AUTOCANNON_AMMO_GROUP_ID => 'ammunition',
        self::COMPOSITE_GROUP_ID => 'equipment',
        self::COMPOSITE_REACTION_FORMULAS_GROUP_ID => 'composite_reaction',
    ];

    public function testManufacturedProductHasTheCategoryOfItsGroup(): void
    {
        $category = $this->rigCategories($this->categoryRepository())->categoryOf(self::HAIL_L, ActivityKind::Manufacturing);

        $this->assertSame('ammunition', $category);
    }

    public function testReactionProductHasTheCategoryOfTheGroupOfItsFormulaNotOfItsOwnGroup(): void
    {
        $category = $this->rigCategories($this->categoryRepository())->categoryOf(self::FERNITE_CARBIDE, ActivityKind::Reaction);

        $this->assertSame('composite_reaction', $category);
    }

    public function testProductOfAGroupNoRigTargetsHasNoCategory(): void
    {
        $category = $this->rigCategories($this->categoryRepository())->categoryOf(self::RAM_AMMUNITION_TECH, ActivityKind::Manufacturing);

        $this->assertNull($category);
    }

    public function testGroupCategoriesAreReadOnceForEveryLookup(): void
    {
        $categoryRepository = $this->createMock(IndustryRigCategoryRepository::class);
        $categoryRepository->expects($this->once())->method('buildGroupCategoryMap')->willReturn(self::GROUP_CATEGORIES);
        $rigCategories = $this->rigCategories($categoryRepository);

        $this->assertSame('ammunition', $rigCategories->categoryOf(self::HAIL_L, ActivityKind::Manufacturing));
        $this->assertSame('composite_reaction', $rigCategories->categoryOf(self::FERNITE_CARBIDE, ActivityKind::Reaction));
        $this->assertNull($rigCategories->categoryOf(self::RAM_AMMUNITION_TECH, ActivityKind::Manufacturing));
    }

    private function rigCategories(IndustryRigCategoryRepository $categoryRepository): SdeRigCategories
    {
        return new SdeRigCategories($categoryRepository, $this->activityProductRepository(), $this->invTypeRepository());
    }

    private function categoryRepository(): IndustryRigCategoryRepository
    {
        $repository = $this->createStub(IndustryRigCategoryRepository::class);
        $repository->method('buildGroupCategoryMap')->willReturn(self::GROUP_CATEGORIES);

        return $repository;
    }

    private function activityProductRepository(): IndustryActivityProductRepository
    {
        $ferniteCarbideReaction = (new IndustryActivityProduct())
            ->setTypeId(self::FERNITE_CARBIDE_REACTION_FORMULA)
            ->setActivityId(self::ACTIVITY_REACTION)
            ->setProductTypeId(self::FERNITE_CARBIDE)
            ->setQuantity(10000);

        $repository = $this->createStub(IndustryActivityProductRepository::class);
        $repository->method('findBlueprintForProduct')->willReturnCallback(
            static fn (int $productTypeId, int $activityId = 1): ?IndustryActivityProduct => self::FERNITE_CARBIDE === $productTypeId && self::ACTIVITY_REACTION === $activityId
                ? $ferniteCarbideReaction
                : null,
        );

        return $repository;
    }

    private function invTypeRepository(): InvTypeRepository
    {
        $types = [];
        foreach ([
            [self::HAIL_L, 'Hail L', self::ADVANCED_AUTOCANNON_AMMO_GROUP_ID],
            [self::RAM_AMMUNITION_TECH, 'R.A.M.- Ammunition Tech', self::TOOL_GROUP_ID],
            [self::FERNITE_CARBIDE, 'Fernite Carbide', self::COMPOSITE_GROUP_ID],
            [self::FERNITE_CARBIDE_REACTION_FORMULA, 'Fernite Carbide Reaction Formula', self::COMPOSITE_REACTION_FORMULAS_GROUP_ID],
        ] as [$typeId, $name, $groupId]) {
            $types[$typeId] = (new InvType())
                ->setTypeId($typeId)
                ->setTypeName($name)
                ->setGroup((new InvGroup())->setGroupId($groupId));
        }

        $repository = $this->createStub(InvTypeRepository::class);
        $repository->method('find')->willReturnCallback(static fn (mixed $typeId): ?InvType => $types[$typeId] ?? null);

        return $repository;
    }
}
