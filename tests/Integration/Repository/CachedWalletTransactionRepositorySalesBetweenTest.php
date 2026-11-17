<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\CachedWalletTransaction;
use App\Entity\Character;
use App\Repository\CachedWalletTransactionRepository;
use App\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('issue-1068')]
final class CachedWalletTransactionRepositorySalesBetweenTest extends IntegrationTestCase
{
    private const int COOLANT = 9832;
    private const int ROBOTICS = 9848;
    private const int WATER = 3645;

    private CachedWalletTransactionRepository $repository;
    private Character $pilot;
    private Character $stranger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = self::getContainer()->get(CachedWalletTransactionRepository::class);
        $this->pilot = $this->createCharacter($this->createUser(), 'Sales Pilot')->setEveCharacterId(2168000001);
        $this->stranger = $this->createCharacter($this->createUser(), 'Sales Stranger')->setEveCharacterId(2168000002);

        $this->transaction(6_800_000_001, $this->pilot, false, self::COOLANT, '2026-11-10T00:00:00+00:00');
        $this->transaction(6_800_000_002, $this->pilot, false, self::ROBOTICS, '2026-11-16T12:00:00+00:00');
        $this->transaction(6_800_000_003, $this->pilot, true, self::COOLANT, '2026-11-12T12:00:00+00:00');
        $this->transaction(6_800_000_004, $this->pilot, false, self::WATER, '2026-11-12T12:00:00+00:00');
        $this->transaction(6_800_000_005, $this->stranger, false, self::COOLANT, '2026-11-12T12:00:00+00:00');
        $this->transaction(6_800_000_006, $this->pilot, false, self::COOLANT, '2026-11-18T00:00:00+00:00');
        $this->transaction(6_800_000_007, $this->pilot, false, self::COOLANT, '2026-11-09T23:59:59+00:00');
        $this->em->flush();
    }

    public function testReturnsOnlyTheSalesOfTheGivenCharactersAndTypesInTheHalfOpenPeriodOldestFirst(): void
    {
        $sales = $this->repository->findSalesByTypeIdsBetween(
            [$this->pilot],
            [self::COOLANT, self::ROBOTICS],
            new \DateTimeImmutable('2026-11-10T00:00:00+00:00'),
            new \DateTimeImmutable('2026-11-18T00:00:00+00:00'),
        );

        $this->assertSame([6_800_000_001, 6_800_000_002], array_map(static fn (CachedWalletTransaction $sale): int => $sale->getTransactionId(), $sales));
    }

    public function testReturnsNothingWithoutCharactersOrTypes(): void
    {
        $from = new \DateTimeImmutable('2026-11-01T00:00:00+00:00');
        $until = new \DateTimeImmutable('2026-12-01T00:00:00+00:00');

        $this->assertSame([[], []], [
            $this->repository->findSalesByTypeIdsBetween([], [self::COOLANT], $from, $until),
            $this->repository->findSalesByTypeIdsBetween([$this->pilot], [], $from, $until),
        ]);
    }

    private function transaction(int $transactionId, Character $character, bool $isBuy, int $typeId, string $date): void
    {
        $this->em->persist(
            (new CachedWalletTransaction())
                ->setCharacter($character)
                ->setTransactionId($transactionId)
                ->setIsBuy($isBuy)
                ->setTypeId($typeId)
                ->setQuantity(1)
                ->setUnitPrice(1_000.0)
                ->setDate(new \DateTimeImmutable($date))
                ->setLocationId(60003760),
        );
    }
}
