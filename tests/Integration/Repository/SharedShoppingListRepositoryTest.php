<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\SharedShoppingList;
use App\Repository\SharedShoppingListRepository;
use App\Tests\Integration\IntegrationTestCase;

/**
 * Issue #34 : `deleteExpired()` est la purge quotidienne des listes partagées expirées.
 */
final class SharedShoppingListRepositoryTest extends IntegrationTestCase
{
    private SharedShoppingListRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = self::getContainer()->get(SharedShoppingListRepository::class);
        // Retire les listes expirées déjà présentes en base de test (transaction annulée en fin de test),
        // pour que le nombre de suppressions ne compte que les listes du test.
        $this->repository->deleteExpired();
    }

    public function testDeleteExpiredDeletesListsWhoseExpiryIsPastAndKeepsTheOthers(): void
    {
        $expiredYesterday = $this->createSharedList(new \DateTimeImmutable('-1 day'));
        $expiredLastMonth = $this->createSharedList(new \DateTimeImmutable('-30 days'));
        $expiringTomorrow = $this->createSharedList(new \DateTimeImmutable('+1 day'));
        $this->flushAndClear();

        $deletedCount = $this->repository->deleteExpired();

        self::assertSame(2, $deletedCount);
        self::assertNull($this->repository->find($expiredYesterday->getId()));
        self::assertNull($this->repository->find($expiredLastMonth->getId()));
        self::assertNotNull($this->repository->find($expiringTomorrow->getId()));
    }

    public function testDeleteExpiredDeletesNothingWhenNoListIsExpired(): void
    {
        $expiringNextWeek = $this->createSharedList(new \DateTimeImmutable('+1 week'));
        $this->flushAndClear();

        self::assertSame(0, $this->repository->deleteExpired());
        self::assertNotNull($this->repository->find($expiringNextWeek->getId()));
    }

    private function createSharedList(\DateTimeImmutable $expiresAt): SharedShoppingList
    {
        $sharedList = (new SharedShoppingList())
            ->setData(['items' => [['typeId' => 34, 'quantity' => 1000]]])
            ->setExpiresAt($expiresAt);
        $this->em->persist($sharedList);

        return $sharedList;
    }
}
