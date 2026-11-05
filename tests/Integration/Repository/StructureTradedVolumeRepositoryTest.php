<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\StructureTradedVolume;
use App\Repository\StructureTradedVolumeRepository;
use App\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(StructureTradedVolumeRepository::class)]
final class StructureTradedVolumeRepositoryTest extends IntegrationTestCase
{
    private const int STRUCTURE_ID = 1_035_466_617_946;
    private const int TRITANIUM = 34;
    private const int PYERITE = 35;

    private StructureTradedVolumeRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = self::getContainer()->get(StructureTradedVolumeRepository::class);
    }

    public function testPurgeOlderThanDeletesRowsOlderThanTheGivenDaysAndKeepsTheOthers(): void
    {
        $this->persistTradedVolume(self::TRITANIUM, '-91 days', 420);
        $this->persistTradedVolume(self::PYERITE, '-89 days', 135);
        $this->flushAndClear();

        $deleted = $this->repository->purgeOlderThan(90);

        self::assertSame(1, $deleted);
        $remaining = $this->repository->findAll();
        self::assertCount(1, $remaining);
        self::assertSame(self::PYERITE, $remaining[0]->getTypeId());
        self::assertSame(135, $remaining[0]->getTradedVolume());
    }

    public function testPurgeOlderThanReturnsZeroWhenNoRowIsOldEnough(): void
    {
        $this->persistTradedVolume(self::TRITANIUM, '-10 days', 420);
        $this->flushAndClear();

        self::assertSame(0, $this->repository->purgeOlderThan(90));
        self::assertCount(1, $this->repository->findAll());
    }

    private function persistTradedVolume(int $typeId, string $relativeDate, int $tradedVolume): void
    {
        $this->em->persist(
            (new StructureTradedVolume())
                ->setStructureId(self::STRUCTURE_ID)
                ->setTypeId($typeId)
                ->setDate(new \DateTimeImmutable($relativeDate))
                ->setTradedVolume($tradedVolume),
        );
    }
}
