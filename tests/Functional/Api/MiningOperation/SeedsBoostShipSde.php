<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\MiningOperation;

use App\Entity\Sde\DgmAttributeType;
use App\Entity\Sde\DgmTypeAttribute;
use App\Entity\Sde\InvCategory;
use App\Entity\Sde\InvGroup;
use App\Entity\Sde\InvType;
use Doctrine\ORM\EntityManagerInterface;

trait SeedsBoostShipSde
{
    private const int PORPOISE = 42244;
    private const int ORCA = 28606;
    private const int RORQUAL = 28352;
    private const int CAN_FIT_SHIP_TYPE_1 = 1302;
    private const int COMPRESSIBLE_ITEMS_TYPE_LIST = 3255;
    private const int COMPRESSORS_GROUP = 4174;
    private const int BLUE_ICE = 16264;
    private const int ZEOLITES = 45490;

    private const array COMPRESSORS = [
        [62622, 'Medium Asteroid Ore Compressor I', self::PORPOISE, 334],
        [62624, 'Medium Gas Compressor I', self::PORPOISE, 332],
        [62625, 'Large Asteroid Ore Compressor I', self::ORCA, 334],
        [62626, 'Large Gas Compressor I', self::ORCA, 332],
        [62628, 'Large Ice Compressor I', self::ORCA, 331],
        [62630, 'Large Mercoxit Compressor I', self::ORCA, 333],
        [62631, 'Large Moon Ore Compressor I', self::ORCA, 330],
        [62632, 'Capital Asteroid Ore Compressor I', self::RORQUAL, 334],
        [62633, 'Capital Ice Compressor I', self::RORQUAL, 331],
        [62634, 'Capital Gas Compressor I', self::RORQUAL, 332],
        [62635, 'Capital Mercoxit Compressor I', self::RORQUAL, 333],
        [62636, 'Capital Moon Ore Compressor I', self::RORQUAL, 330],
    ];

    private function seedBoostShipSde(EntityManagerInterface $em): void
    {
        $asteroids = $this->sdeCategory($em, 25, 'Asteroid');
        $ships = $this->sdeCategory($em, 6, 'Ship');
        $modules = $this->sdeCategory($em, 7, 'Module');
        $compressorsGroup = $this->sdeGroup($em, self::COMPRESSORS_GROUP, 'Compressors', $modules);
        $miningShips = $this->sdeGroup($em, 463, 'Mining Barge', $ships);
        $moonGroup = $this->sdeGroup($em, 1884, 'Ubiquitous Moon Asteroids', $asteroids);
        $iceGroup = $this->sdeGroup($em, 465, 'Ice', $asteroids);

        $this->sdeType($em, self::PORPOISE, 'Porpoise', $miningShips);
        $this->sdeType($em, self::ORCA, 'Orca', $miningShips);
        $this->sdeType($em, self::RORQUAL, 'Rorqual', $miningShips);
        $this->sdeType($em, self::ZEOLITES, 'Zeolites', $moonGroup);
        $this->sdeType($em, self::BLUE_ICE, 'Blue Ice', $iceGroup);

        $em->persist((new DgmAttributeType())->setAttributeId(self::CAN_FIT_SHIP_TYPE_1)->setAttributeName('canFitShipType1'));
        $em->persist((new DgmAttributeType())->setAttributeId(self::COMPRESSIBLE_ITEMS_TYPE_LIST)->setAttributeName('compressibleItemsTypeList'));
        foreach (self::COMPRESSORS as [$typeId, $typeName, $shipTypeId, $kindList]) {
            $this->sdeType($em, $typeId, $typeName, $compressorsGroup);
            $em->persist((new DgmTypeAttribute())->setTypeId($typeId)->setAttributeId(self::CAN_FIT_SHIP_TYPE_1)->setValueInt($shipTypeId));
            $em->persist((new DgmTypeAttribute())->setTypeId($typeId)->setAttributeId(self::COMPRESSIBLE_ITEMS_TYPE_LIST)->setValueInt($kindList));
        }
    }

    private function sdeCategory(EntityManagerInterface $em, int $categoryId, string $name): InvCategory
    {
        $category = $em->find(InvCategory::class, $categoryId);
        if ($category === null) {
            $category = (new InvCategory())->setCategoryId($categoryId)->setCategoryName($name);
            $em->persist($category);
        }

        return $category;
    }

    private function sdeGroup(EntityManagerInterface $em, int $groupId, string $name, InvCategory $category): InvGroup
    {
        $group = $em->find(InvGroup::class, $groupId);
        if ($group === null) {
            $group = (new InvGroup())->setGroupId($groupId)->setGroupName($name)->setCategory($category);
            $em->persist($group);
        }

        return $group;
    }

    private function sdeType(EntityManagerInterface $em, int $typeId, string $name, InvGroup $group): void
    {
        if ($em->find(InvType::class, $typeId) === null) {
            $em->persist((new InvType())->setTypeId($typeId)->setTypeName($name)->setGroup($group)->setPublished(true));
        }
    }
}
