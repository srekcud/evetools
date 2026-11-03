<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\CachedAsset;
use App\Entity\Character;
use App\Entity\User;
use App\Repository\CachedAssetRepository;
use App\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('issue-314')]
final class CachedAssetRepositoryAggregatedQuantitiesTest extends IntegrationTestCase
{
    private const int COMPRESSED_VELDSPAR = 62516;
    private const int COMPRESSED_CLEAR_ICICLE = 28434;
    private const int JITA_STATION_ID = 60_003_760;
    private const int CORPORATION_ID = 98_000_001;

    private static int $nextItemId = 1_040_314_000_001;

    public function testPersonalQuantitiesOfEveryCharacterOfTheUserAreSummedByTypeWithoutCorporationOrOtherUserAssets(): void
    {
        $user = $this->createUser();
        $miner = $this->createCharacter($user, 'Ilyra Castell');
        $hauler = $this->createCharacter($user, 'Teo Varn');
        $otherUserPilot = $this->createCharacter(null, 'Someone Else');

        $this->personalAsset($miner, self::COMPRESSED_VELDSPAR, 12_000);
        $this->personalAsset($hauler, self::COMPRESSED_VELDSPAR, 8_000);
        $this->personalAsset($hauler, self::COMPRESSED_CLEAR_ICICLE, 5);
        $this->personalAsset($hauler, self::COMPRESSED_CLEAR_ICICLE, 2);
        $this->corporationAssetSyncedBy($miner, self::COMPRESSED_VELDSPAR, 50_000);
        $this->personalAsset($otherUserPilot, self::COMPRESSED_VELDSPAR, 7_300);
        $this->flushAndClear();

        $quantities = self::getContainer()->get(CachedAssetRepository::class)
            ->getAggregatedQuantitiesByUser($this->em->find(User::class, $user->getId()));
        ksort($quantities);

        self::assertSame([self::COMPRESSED_CLEAR_ICICLE => 7, self::COMPRESSED_VELDSPAR => 20_000], $quantities);
    }

    private function personalAsset(Character $character, int $typeId, int $quantity): void
    {
        $this->em->persist($this->asset($typeId, $quantity)->setCharacter($character)->setIsCorporationAsset(false));
    }

    private function corporationAssetSyncedBy(Character $character, int $typeId, int $quantity): void
    {
        $this->em->persist($this->asset($typeId, $quantity)
            ->setCharacter($character)
            ->setCorporationId(self::CORPORATION_ID)
            ->setLocationFlag('CorpSAG1')
            ->setIsCorporationAsset(true));
    }

    private function asset(int $typeId, int $quantity): CachedAsset
    {
        return (new CachedAsset())
            ->setItemId(self::$nextItemId++)
            ->setTypeId($typeId)
            ->setTypeName(null)
            ->setQuantity($quantity)
            ->setLocationId(self::JITA_STATION_ID)
            ->setLocationName('Jita IV - Moon 4 - Caldari Navy Assembly Plant')
            ->setLocationType('station')
            ->setLocationFlag('Hangar');
    }
}
