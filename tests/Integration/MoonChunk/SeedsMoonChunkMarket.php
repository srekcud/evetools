<?php

declare(strict_types=1);

namespace App\Tests\Integration\MoonChunk;

use App\Entity\Sde\InvCategory;
use App\Entity\Sde\InvGroup;
use App\Entity\Sde\InvType;
use App\Tests\Integration\MiningSite\SeedsMiningSiteMarket;
use Doctrine\ORM\EntityManagerInterface;

trait SeedsMoonChunkMarket
{
    use SeedsMiningSiteMarket;

    private const int BITUMENS = 45492;
    private const int COMPRESSED_BITUMENS = 62568;
    private const int CHROMITE = 45501;
    private const int COMPRESSED_CHROMITE = 62572;
    private const int ZEOLITES = 45490;
    private const int COMPRESSED_ZEOLITES = 62566;
    private const int SYLVITE = 45499;
    private const int COMPRESSED_SYLVITE = 62571;
    private const int UNPUBLISHED_CINNABAR = 45502;
    private const int TRITANIUM = 34;
    private const float BITUMENS_VOLUME = 10.0;
    private const float CHROMITE_VOLUME = 16.0;
    private const float ZEOLITES_VOLUME = 5.0;
    private const float SYLVITE_VOLUME = 12.0;

    private function seedMoonOreSde(EntityManagerInterface $em): void
    {
        $this->jitaBuyPrices[self::COMPRESSED_BITUMENS] = 100.0;
        $this->jitaBuyPrices[self::COMPRESSED_CHROMITE] = 300.0;

        $asteroid = (new InvCategory())->setCategoryId(25)->setCategoryName('Asteroid');
        $material = (new InvCategory())->setCategoryId(4)->setCategoryName('Material');
        $ubiquitousGroup = (new InvGroup())->setGroupId(1884)->setGroupName('Ubiquitous Moon Asteroids')->setCategory($asteroid);
        $commonGroup = (new InvGroup())->setGroupId(1920)->setGroupName('Common Moon Asteroids')->setCategory($asteroid);
        $mineralGroup = (new InvGroup())->setGroupId(18)->setGroupName('Mineral')->setCategory($material);
        foreach ([$asteroid, $material, $ubiquitousGroup, $commonGroup, $mineralGroup] as $staticRow) {
            $em->persist($staticRow);
        }
        foreach ([
            [self::BITUMENS, 'Bitumens', $ubiquitousGroup, self::BITUMENS_VOLUME, true],
            [self::COMPRESSED_BITUMENS, 'Compressed Bitumens', $ubiquitousGroup, 0.1, true],
            [self::CHROMITE, 'Chromite', $commonGroup, self::CHROMITE_VOLUME, true],
            [self::COMPRESSED_CHROMITE, 'Compressed Chromite', $commonGroup, 0.16, true],
            [self::ZEOLITES, 'Zeolites', $ubiquitousGroup, self::ZEOLITES_VOLUME, true],
            [self::COMPRESSED_ZEOLITES, 'Compressed Zeolites', $ubiquitousGroup, 0.05, true],
            [self::SYLVITE, 'Sylvite', $commonGroup, self::SYLVITE_VOLUME, true],
            [self::COMPRESSED_SYLVITE, 'Compressed Sylvite', $commonGroup, 0.12, true],
            [self::UNPUBLISHED_CINNABAR, 'Cinnabar', $commonGroup, 16.0, false],
            [self::TRITANIUM, 'Tritanium', $mineralGroup, 0.01, true],
        ] as [$typeId, $typeName, $group, $volume, $published]) {
            $em->persist((new InvType())->setTypeId($typeId)->setTypeName($typeName)->setGroup($group)->setVolume($volume)->setPortionSize(100)->setPublished($published));
        }
    }
}
