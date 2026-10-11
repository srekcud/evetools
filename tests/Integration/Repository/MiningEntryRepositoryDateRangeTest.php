<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\MiningEntry;
use App\Entity\User;
use App\Repository\MiningEntryRepository;
use App\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Group;

final class MiningEntryRepositoryDateRangeTest extends IntegrationTestCase
{
    private const VELDSPAR = 1230;
    private const SCORDITE = 1228;
    private const KERNITE = 20;

    private MiningEntryRepository $repository;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = self::getContainer()->get(MiningEntryRepository::class);

        $this->user = $this->createUser();
        $this->addEntry('2026-09-11', self::VELDSPAR, 'Veldspar', 4_211, 17.25);
        $this->addEntry('2026-09-12', self::SCORDITE, 'Scordite', 2_906, 11.75);
        $this->addEntry('2026-10-11', self::KERNITE, 'Kernite', 517, 51.5);
        $this->flushAndClear();
        $this->user = $this->em->getRepository(User::class)->find($this->user->getId());
    }

    #[Group('mining-audit-P3-1')]
    public function testDailyTotalsKeepTheFirstDayWhenThePeriodStartCarriesATimeOfDay(): void
    {
        $dailyTotals = $this->repository->getDailyTotals(
            $this->user,
            new \DateTimeImmutable('2026-09-11 14:37:00'),
            new \DateTimeImmutable('2026-10-11 14:37:00'),
        );

        $this->assertSame([
            '2026-09-11' => ['date' => '2026-09-11', 'totalValue' => 72_639.75, 'totalQuantity' => 4_211],
            '2026-09-12' => ['date' => '2026-09-12', 'totalValue' => 34_145.5, 'totalQuantity' => 2_906],
            '2026-10-11' => ['date' => '2026-10-11', 'totalValue' => 26_625.5, 'totalQuantity' => 517],
        ], $dailyTotals);
    }

    #[Group('mining-audit-P3-1')]
    public function testQuantitiesByTypeAndDateKeepTheFirstDayWhenThePeriodStartCarriesATimeOfDay(): void
    {
        $quantities = $this->repository->getQuantitiesByTypeAndDate(
            $this->user,
            new \DateTimeImmutable('2026-09-11 14:37:00'),
            new \DateTimeImmutable('2026-10-11 14:37:00'),
        );
        ksort($quantities);

        $this->assertSame([
            '2026-09-11' => [self::VELDSPAR => 4_211],
            '2026-09-12' => [self::SCORDITE => 2_906],
            '2026-10-11' => [self::KERNITE => 517],
        ], $quantities);
    }

    #[Group('mining-audit-P3-1')]
    public function testEntriesInDateRangeKeepTheFirstDayWhenThePeriodStartCarriesATimeOfDay(): void
    {
        $entries = $this->repository->findByUserAndDateRange(
            $this->user,
            new \DateTimeImmutable('2026-09-11 14:37:00'),
            new \DateTimeImmutable('2026-10-11 14:37:00'),
        );

        $this->assertSame([
            ['2026-10-11', self::KERNITE, 517],
            ['2026-09-12', self::SCORDITE, 2_906],
            ['2026-09-11', self::VELDSPAR, 4_211],
        ], array_map(static fn (MiningEntry $entry): array => [
            $entry->getDate()->format('Y-m-d'),
            $entry->getTypeId(),
            $entry->getQuantity(),
        ], $entries));
    }

    #[Group('mining-audit-P3-1')]
    public function testDailyTotalsExcludeTheDayBeforeThePeriodStart(): void
    {
        $dailyTotals = $this->repository->getDailyTotals(
            $this->user,
            new \DateTimeImmutable('2026-09-12 00:00:00'),
            new \DateTimeImmutable('2026-10-10 23:59:59'),
        );

        $this->assertSame([
            '2026-09-12' => ['date' => '2026-09-12', 'totalValue' => 34_145.5, 'totalQuantity' => 2_906],
        ], $dailyTotals);
    }

    private function addEntry(string $date, int $typeId, string $typeName, int $quantity, float $unitPrice): void
    {
        $entry = (new MiningEntry())
            ->setUser($this->user)
            ->setCharacterId(93000001)
            ->setCharacterName('Maren Esk')
            ->setDate(new \DateTimeImmutable($date))
            ->setTypeId($typeId)
            ->setTypeName($typeName)
            ->setSolarSystemId(30003504)
            ->setSolarSystemName('Aldranette')
            ->setQuantity($quantity)
            ->setUnitPrice($unitPrice);
        $this->em->persist($entry);
    }
}
