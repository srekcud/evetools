<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Constant\EveConstants;
use App\Entity\MarketPriceHistory;
use App\Repository\MarketPriceHistoryRepository;
use App\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('issue-1404')]
final class MarketPriceHistoryRepositoryLatestEntriesTest extends IntegrationTestCase
{
    private const int COOLANT = 91_021;
    private const int MECHANICAL_PARTS = 91_022;
    private const int ROBOTICS = 91_031;
    private const int THE_FORGE = EveConstants::THE_FORGE_REGION_ID;
    private const int DOMAIN = 10_000_043;

    private MarketPriceHistoryRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = self::getContainer()->get(MarketPriceHistoryRepository::class);
    }

    public function testOnlyTheLatestEntriesOfEachTypeComeBackWithinTheLimitAndInAscendingDateOrder(): void
    {
        foreach ([1 => 101.5, 2 => 102.5, 3 => 103.5, 4 => 104.5, 5 => 105.5] as $day => $average) {
            $this->persistEntry(self::COOLANT, self::THE_FORGE, $day, $average, 1_000 + $day);
        }
        foreach ([2 => 2_010.0, 3 => 2_020.0] as $day => $average) {
            $this->persistEntry(self::MECHANICAL_PARTS, self::THE_FORGE, $day, $average, 50 + $day);
        }
        $this->flushAndClear();

        $entries = $this->repository->findLatestEntriesByType([self::COOLANT, self::MECHANICAL_PARTS], self::THE_FORGE, 3);

        self::assertSame([
            self::COOLANT => [
                ['date' => '2026-03-03', 'average' => 103.5, 'volume' => 1_003],
                ['date' => '2026-03-04', 'average' => 104.5, 'volume' => 1_004],
                ['date' => '2026-03-05', 'average' => 105.5, 'volume' => 1_005],
            ],
            self::MECHANICAL_PARTS => [
                ['date' => '2026-03-02', 'average' => 2_010.0, 'volume' => 52],
                ['date' => '2026-03-03', 'average' => 2_020.0, 'volume' => 53],
            ],
        ], $entries);
    }

    public function testTheEntriesOfAnotherRegionAreIgnored(): void
    {
        $this->persistEntry(self::COOLANT, self::THE_FORGE, 1, 100.0, 10);
        $this->persistEntry(self::COOLANT, self::DOMAIN, 2, 5_000.0, 999);
        $this->persistEntry(self::ROBOTICS, self::DOMAIN, 3, 77_000.0, 5);
        $this->flushAndClear();

        $entries = $this->repository->findLatestEntriesByType([self::COOLANT, self::ROBOTICS], self::THE_FORGE, 60);

        self::assertSame([self::COOLANT => [['date' => '2026-03-01', 'average' => 100.0, 'volume' => 10]]], $entries);
    }

    public function testATypeWithoutRowsAndATypeThatWasNotAskedAreAbsent(): void
    {
        $this->persistEntry(self::COOLANT, self::THE_FORGE, 1, 100.0, 10);
        $this->persistEntry(self::ROBOTICS, self::THE_FORGE, 1, 29_800.0, 460);
        $this->flushAndClear();

        $entries = $this->repository->findLatestEntriesByType([self::COOLANT, self::MECHANICAL_PARTS], self::THE_FORGE, 60);

        self::assertSame([self::COOLANT], array_keys($entries));
    }

    public function testSixtyOneRowsKeepTheLatestSixtyOfTheType(): void
    {
        foreach (range(1, 61) as $day) {
            $this->persistEntry(self::ROBOTICS, self::THE_FORGE, $day, 29_000.0 + $day, 400 + $day);
        }
        $this->flushAndClear();

        $entriesByType = $this->repository->findLatestEntriesByType([self::ROBOTICS], self::THE_FORGE, 60);

        self::assertArrayHasKey(self::ROBOTICS, $entriesByType);
        $entries = $entriesByType[self::ROBOTICS];
        self::assertCount(60, $entries);
        self::assertSame(['date' => '2026-03-02', 'average' => 29_002.0, 'volume' => 402], $entries[0]);
        self::assertSame(['date' => '2026-04-30', 'average' => 29_061.0, 'volume' => 461], $entries[59]);
    }

    public function testNoTypeGivesNoEntries(): void
    {
        $this->persistEntry(self::COOLANT, self::THE_FORGE, 1, 100.0, 10);
        $this->flushAndClear();

        self::assertSame([], $this->repository->findLatestEntriesByType([], self::THE_FORGE, 60));
    }

    private function persistEntry(int $typeId, int $regionId, int $dayOfHistory, float $average, int $volume): void
    {
        $this->em->persist(
            (new MarketPriceHistory())
                ->setTypeId($typeId)
                ->setRegionId($regionId)
                ->setDate((new \DateTime('2026-02-28'))->modify(sprintf('+%d days', $dayOfHistory)))
                ->setAverage($average)
                ->setHighest($average + 3.0)
                ->setLowest($average - 3.0)
                ->setOrderCount(12)
                ->setVolume($volume),
        );
    }
}
