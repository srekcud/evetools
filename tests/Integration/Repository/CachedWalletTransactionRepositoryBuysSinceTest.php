<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\CachedWalletTransaction;
use App\Entity\Character;
use App\Repository\CachedWalletTransactionRepository;
use App\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('issue-1396')]
final class CachedWalletTransactionRepositoryBuysSinceTest extends IntegrationTestCase
{
    private const int WATER = 3645;
    private const int COOLANT = 9832;
    private const int TRITANIUM = 34;

    private CachedWalletTransactionRepository $repository;
    private Character $pilot;
    private Character $alt;
    private Character $stranger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = self::getContainer()->get(CachedWalletTransactionRepository::class);
        $user = $this->createUser();
        $this->pilot = $this->createCharacter($user, 'Buys Pilot')->setEveCharacterId(2196000001);
        $this->alt = $this->createCharacter($user, 'Buys Alt')->setEveCharacterId(2196000002);
        $this->stranger = $this->createCharacter($this->createUser(), 'Buys Stranger')->setEveCharacterId(2196000003);

        $this->transaction(6_960_000_001, $this->pilot, true, self::WATER, '2026-11-10T00:00:00+00:00');
        $this->transaction(6_960_000_002, $this->alt, true, self::COOLANT, '2026-11-12T00:00:00+00:00');
        $this->transaction(6_960_000_003, $this->pilot, false, self::WATER, '2026-11-12T00:00:00+00:00');
        $this->transaction(6_960_000_004, $this->pilot, true, self::TRITANIUM, '2026-11-12T00:00:00+00:00');
        $this->transaction(6_960_000_005, $this->stranger, true, self::WATER, '2026-11-12T00:00:00+00:00');
        $this->transaction(6_960_000_006, $this->pilot, true, self::WATER, '2026-11-09T23:59:59+00:00');
        $this->em->flush();
    }

    public function testReturnsOnlyTheBuysOfTheGivenCharactersAndTypesFromTheBoundOnwardsOldestFirst(): void
    {
        $buys = $this->repository->findBuysByCharactersSince(
            [$this->pilot, $this->alt],
            [self::WATER, self::COOLANT],
            new \DateTimeImmutable('2026-11-10T00:00:00+00:00'),
        );

        $this->assertSame([6_960_000_001, 6_960_000_002], array_map(static fn (CachedWalletTransaction $buy): int => $buy->getTransactionId(), $buys));
    }

    public function testReturnsNothingWithoutCharactersOrTypes(): void
    {
        $since = new \DateTimeImmutable('2026-11-01T00:00:00+00:00');

        $this->assertSame([[], []], [
            $this->repository->findBuysByCharactersSince([], [self::WATER], $since),
            $this->repository->findBuysByCharactersSince([$this->pilot], [], $since),
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
