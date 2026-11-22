<?php

declare(strict_types=1);

namespace App\Tests\Integration\Industry\Infrastructure\Sde;

use App\Constant\EveConstants;
use App\Entity\Sde\InvCategory;
use App\Entity\Sde\InvGroup;
use App\Entity\Sde\InvType;
use App\Industry\Application\ShipProducts;
use App\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('issue-1285')]
final class SdeShipProductsTest extends IntegrationTestCase
{
    private const int FIXTURE_MODULE_CATEGORY_ID = 991_285;
    private const int FIXTURE_SHIP_GROUP_ID = 991_285_001;
    private const int FIXTURE_MODULE_GROUP_ID = 991_285_002;
    private const int FIXTURE_HEAVY_ASSAULT_CRUISER = 991_285_101;
    private const int FIXTURE_ADAPTIVE_NANO_PLATING = 991_285_102;
    private const int TYPE_UNKNOWN_TO_SDE = 991_285_199;

    protected function setUp(): void
    {
        parent::setUp();
        $ships = $this->em->find(InvCategory::class, EveConstants::SHIP_CATEGORY_ID)
            ?? (new InvCategory())->setCategoryId(EveConstants::SHIP_CATEGORY_ID)->setCategoryName('Ship')->setPublished(true);
        $modules = (new InvCategory())->setCategoryId(self::FIXTURE_MODULE_CATEGORY_ID)->setCategoryName('Fixture Module 1285')->setPublished(true);
        $shipGroup = (new InvGroup())->setGroupId(self::FIXTURE_SHIP_GROUP_ID)->setGroupName('Fixture Heavy Assault Cruiser 1285')->setCategory($ships)->setPublished(true);
        $moduleGroup = (new InvGroup())->setGroupId(self::FIXTURE_MODULE_GROUP_ID)->setGroupName('Fixture Armor Plate 1285')->setCategory($modules)->setPublished(true);
        foreach ([$ships, $modules, $shipGroup, $moduleGroup] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->persist((new InvType())->setTypeId(self::FIXTURE_HEAVY_ASSAULT_CRUISER)->setTypeName('Fixture Ishtar 1285')->setGroup($shipGroup)->setPublished(true));
        $this->em->persist((new InvType())->setTypeId(self::FIXTURE_ADAPTIVE_NANO_PLATING)->setTypeName('Fixture Adaptive Nano Plating 1285')->setGroup($moduleGroup)->setPublished(true));
        $this->flushAndClear();
    }

    public function testTypeOfTheShipCategoryIsAShip(): void
    {
        $this->assertTrue($this->shipProducts()->isShip(self::FIXTURE_HEAVY_ASSAULT_CRUISER));
    }

    public function testTypeOfAnotherCategoryIsNotAShip(): void
    {
        $this->assertFalse($this->shipProducts()->isShip(self::FIXTURE_ADAPTIVE_NANO_PLATING));
    }

    public function testTypeMissingFromTheSdeIsAnExplicitError(): void
    {
        $this->expectException(\UnexpectedValueException::class);

        $this->shipProducts()->isShip(self::TYPE_UNKNOWN_TO_SDE);
    }

    private function shipProducts(): ShipProducts
    {
        $shipProducts = self::getContainer()->get(ShipProducts::class);
        $this->assertInstanceOf(ShipProducts::class, $shipProducts);

        return $shipProducts;
    }
}
