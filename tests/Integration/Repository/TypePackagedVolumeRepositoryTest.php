<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\CachedAsset;
use App\Repository\CachedAssetRepository;
use App\Repository\TypePackagedVolumeRepository;
use App\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('issue-890')]
final class TypePackagedVolumeRepositoryTest extends IntegrationTestCase
{
    private const int RIFTER_TYPE_ID = 890_587;
    private const int TRITANIUM_TYPE_ID = 890_034;
    private const int CORPORATION_ID = 98_890_001;

    private TypePackagedVolumeRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = self::getContainer()->get(TypePackagedVolumeRepository::class);
    }

    public function testUpsertInsertsThenUpdatesTheSameType(): void
    {
        $this->repository->upsert(self::RIFTER_TYPE_ID, 2_500.0, new \DateTimeImmutable('2026-11-01 10:00:00'));
        $this->repository->upsert(self::RIFTER_TYPE_ID, 2_400.0, new \DateTimeImmutable('2026-11-08 10:00:00'));
        $this->repository->upsert(self::TRITANIUM_TYPE_ID, 0.01, new \DateTimeImmutable('2026-11-08 10:00:00'));
        $this->flushAndClear();

        $volumes = $this->repository->findAllByTypeId();

        self::assertSame(2_400.0, $volumes[self::RIFTER_TYPE_ID]);
        self::assertSame(0.01, $volumes[self::TRITANIUM_TYPE_ID]);
    }

    public function testLatestRefreshedAtIsTheNewestRowDate(): void
    {
        $this->repository->upsert(self::RIFTER_TYPE_ID, 2_500.0, new \DateTimeImmutable('2030-01-01 10:00:00'));
        $this->repository->upsert(self::TRITANIUM_TYPE_ID, 0.01, new \DateTimeImmutable('2030-01-08 10:00:00'));
        $this->flushAndClear();

        $latest = $this->repository->findLatestRefreshedAt();

        self::assertNotNull($latest);
        self::assertSame('2030-01-08 10:00:00', $latest->format('Y-m-d H:i:s'));
    }

    public function testDistinctTypeIdsOfCachedAssetsCoverCharactersAndCorporationsOnce(): void
    {
        $this->em->persist($this->asset(1_890_000_001, self::RIFTER_TYPE_ID));
        $this->em->persist($this->asset(1_890_000_002, self::RIFTER_TYPE_ID));
        $this->em->persist($this->asset(1_890_000_003, self::TRITANIUM_TYPE_ID));
        $this->flushAndClear();

        $typeIds = self::getContainer()->get(CachedAssetRepository::class)->findDistinctTypeIds();

        self::assertContains(self::RIFTER_TYPE_ID, $typeIds);
        self::assertContains(self::TRITANIUM_TYPE_ID, $typeIds);
        self::assertSame(count($typeIds), count(array_unique($typeIds)));
    }

    private function asset(int $itemId, int $typeId): CachedAsset
    {
        return (new CachedAsset())
            ->setItemId($itemId)
            ->setTypeId($typeId)
            ->setTypeName('Type '.$typeId)
            ->setQuantity(1)
            ->setLocationId(60_003_760)
            ->setLocationName('Jita IV - Moon 4 - Caldari Navy Assembly Plant')
            ->setLocationType('station')
            ->setLocationFlag('Hangar')
            ->setCorporationId(self::CORPORATION_ID)
            ->setIsCorporationAsset(true);
    }
}
