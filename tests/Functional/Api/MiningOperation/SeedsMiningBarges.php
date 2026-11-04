<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\MiningOperation;

use App\Entity\Sde\InvCategory;
use App\Entity\Sde\InvGroup;
use App\Entity\Sde\InvType;

trait SeedsMiningBarges
{
    private const int HULK = 22544;
    private const int SKIFF = 22546;
    private const int ORCA = 28606;

    private function seedMiningBarges(): void
    {
        $ships = (new InvCategory())->setCategoryId(6)->setCategoryName('Ship');
        $miningBarges = (new InvGroup())->setGroupId(463)->setGroupName('Mining Barge')->setCategory($ships);
        $this->em->persist($ships);
        $this->em->persist($miningBarges);
        foreach ([[self::HULK, 'Hulk'], [self::SKIFF, 'Skiff'], [self::ORCA, 'Orca']] as [$typeId, $typeName]) {
            $this->em->persist((new InvType())->setTypeId($typeId)->setTypeName($typeName)->setGroup($miningBarges)->setPublished(true));
        }
        $this->em->flush();
    }

    private function recordShips(array $shipTypeIdByCharacterName): void
    {
        foreach ($shipTypeIdByCharacterName as $characterName => $shipTypeId) {
            $this->enrolmentNamed($characterName)->recordShip($shipTypeId);
        }
        $this->em->flush();
    }
}
