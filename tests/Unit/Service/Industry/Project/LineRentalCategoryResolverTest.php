<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project;

use App\Entity\Sde\InvCategory;
use App\Entity\Sde\InvGroup;
use App\Entity\Sde\InvType;
use App\Industry\Domain\LineRentalCategory;
use App\Repository\IndustryRigCategoryRepository;
use App\Repository\Sde\InvTypeRepository;
use App\Service\Industry\InventionService;
use App\Service\Industry\Project\LineRentalCategoryResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

#[CoversClass(LineRentalCategoryResolver::class)]
#[Group('issue-300')]
final class LineRentalCategoryResolverTest extends TestCase
{
    private const int SHIP_CATEGORY_ID = 6;
    private const int MODULE_CATEGORY_ID = 7;
    private const int COMMODITY_CATEGORY_ID = 17;
    private const int DRONE_CATEGORY_ID = 18;
    private const int FRIGATE_GROUP_ID = 25;
    private const int CRUISER_GROUP_ID = 26;
    private const int DREADNOUGHT_GROUP_ID = 485;
    private const int FORCE_AUXILIARY_GROUP_ID = 1538;
    private const int ENERGY_WEAPON_GROUP_ID = 53;
    private const int CONSTRUCTION_COMPONENTS_GROUP_ID = 334;
    private const int CAPITAL_CONSTRUCTION_COMPONENTS_GROUP_ID = 873;
    private const int COMBAT_DRONE_GROUP_ID = 100;

    private const int RIFTER_TYPE_ID = 587;
    private const int SABRE_TYPE_ID = 22456;
    private const int MOROS_TYPE_ID = 19724;
    private const int LIF_TYPE_ID = 37604;
    private const int PULSE_LASER_TYPE_ID = 3041;
    private const int PULSE_LASER_II_TYPE_ID = 3057;
    private const int MORPHITE_PLATE_TYPE_ID = 11530;
    private const int CAPITAL_PLATE_TYPE_ID = 21008;
    private const int HOBGOBLIN_II_TYPE_ID = 2454;
    private const int UNKNOWN_TYPE_ID = 999_999_999;

    private const array T2_TYPE_IDS = [self::SABRE_TYPE_ID, self::LIF_TYPE_ID, self::PULSE_LASER_II_TYPE_ID, self::HOBGOBLIN_II_TYPE_ID];

    private InvTypeRepository&Stub $invTypeRepository;
    private LineRentalCategoryResolver $resolver;

    protected function setUp(): void
    {
        $types = [
            self::RIFTER_TYPE_ID => $this->type(self::RIFTER_TYPE_ID, self::FRIGATE_GROUP_ID, self::SHIP_CATEGORY_ID),
            self::SABRE_TYPE_ID => $this->type(self::SABRE_TYPE_ID, self::CRUISER_GROUP_ID, self::SHIP_CATEGORY_ID),
            self::MOROS_TYPE_ID => $this->type(self::MOROS_TYPE_ID, self::DREADNOUGHT_GROUP_ID, self::SHIP_CATEGORY_ID),
            self::LIF_TYPE_ID => $this->type(self::LIF_TYPE_ID, self::FORCE_AUXILIARY_GROUP_ID, self::SHIP_CATEGORY_ID),
            self::PULSE_LASER_TYPE_ID => $this->type(self::PULSE_LASER_TYPE_ID, self::ENERGY_WEAPON_GROUP_ID, self::MODULE_CATEGORY_ID),
            self::PULSE_LASER_II_TYPE_ID => $this->type(self::PULSE_LASER_II_TYPE_ID, self::ENERGY_WEAPON_GROUP_ID, self::MODULE_CATEGORY_ID),
            self::MORPHITE_PLATE_TYPE_ID => $this->type(self::MORPHITE_PLATE_TYPE_ID, self::CONSTRUCTION_COMPONENTS_GROUP_ID, self::COMMODITY_CATEGORY_ID),
            self::CAPITAL_PLATE_TYPE_ID => $this->type(self::CAPITAL_PLATE_TYPE_ID, self::CAPITAL_CONSTRUCTION_COMPONENTS_GROUP_ID, self::COMMODITY_CATEGORY_ID),
            self::HOBGOBLIN_II_TYPE_ID => $this->type(self::HOBGOBLIN_II_TYPE_ID, self::COMBAT_DRONE_GROUP_ID, self::DRONE_CATEGORY_ID),
        ];

        $this->invTypeRepository = $this->createStub(InvTypeRepository::class);
        $this->invTypeRepository->method('findByTypeId')->willReturnCallback(
            static fn (int $typeId): ?InvType => $types[$typeId] ?? null,
        );

        $rigCategoryRepository = $this->createStub(IndustryRigCategoryRepository::class);
        $rigCategoryRepository->method('buildGroupCategoryMap')->willReturn([
            self::FRIGATE_GROUP_ID => 'basic_small_ship',
            self::CRUISER_GROUP_ID => 'basic_medium_ship',
            self::DREADNOUGHT_GROUP_ID => 'capital_ship',
            self::FORCE_AUXILIARY_GROUP_ID => 'capital_ship',
        ]);

        $inventionService = $this->createStub(InventionService::class);
        $inventionService->method('isT2')->willReturnCallback(
            static fn (int $typeId): bool => in_array($typeId, self::T2_TYPE_IDS, true),
        );

        $this->resolver = new LineRentalCategoryResolver($this->invTypeRepository, $rigCategoryRepository, $inventionService);
    }

    public function testT1ShipOutsideTheCapitalGroupsIsShipT1(): void
    {
        self::assertSame(LineRentalCategory::ShipT1, $this->resolver->categoryOf(self::RIFTER_TYPE_ID));
    }

    public function testT2ShipOutsideTheCapitalGroupsIsShipT2(): void
    {
        self::assertSame(LineRentalCategory::ShipT2, $this->resolver->categoryOf(self::SABRE_TYPE_ID));
    }

    public function testT1ShipOfACapitalGroupIsCapitalT1(): void
    {
        self::assertSame(LineRentalCategory::CapitalT1, $this->resolver->categoryOf(self::MOROS_TYPE_ID));
    }

    public function testT2ShipOfACapitalGroupIsCapitalT2(): void
    {
        self::assertSame(LineRentalCategory::CapitalT2, $this->resolver->categoryOf(self::LIF_TYPE_ID));
    }

    public function testT1ModuleIsModuleT1(): void
    {
        self::assertSame(LineRentalCategory::ModuleT1, $this->resolver->categoryOf(self::PULSE_LASER_TYPE_ID));
    }

    public function testT2ModuleIsModuleT2(): void
    {
        self::assertSame(LineRentalCategory::ModuleT2, $this->resolver->categoryOf(self::PULSE_LASER_II_TYPE_ID));
    }

    public function testConstructionComponentIsComponentT1(): void
    {
        self::assertSame(LineRentalCategory::ComponentT1, $this->resolver->categoryOf(self::MORPHITE_PLATE_TYPE_ID));
    }

    public function testCapitalConstructionComponentIsComponentT1(): void
    {
        self::assertSame(LineRentalCategory::ComponentT1, $this->resolver->categoryOf(self::CAPITAL_PLATE_TYPE_ID));
    }

    public function testDroneHasNoCategoryAndIsNeverFallenBackToANeighbourCategory(): void
    {
        self::assertNull($this->resolver->categoryOf(self::HOBGOBLIN_II_TYPE_ID));
    }

    public function testTypeAbsentFromTheSdeHasNoCategoryInsteadOfAnExceptionOrAFallback(): void
    {
        self::assertNull($this->resolver->categoryOf(self::UNKNOWN_TYPE_ID));
    }

    private function type(int $typeId, int $groupId, int $categoryId): InvType
    {
        $category = (new InvCategory())->setCategoryId($categoryId);
        $group = (new InvGroup())->setGroupId($groupId)->setCategory($category);

        return (new InvType())->setTypeId($typeId)->setGroup($group);
    }
}
