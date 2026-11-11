<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\CachedCharacterOrder;
use App\Entity\Character;
use App\Enum\OrderPositionState;
use App\Repository\CachedCharacterOrderRepository;
use App\Dto\OrderPosition;
use App\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('issue-350')]
final class CachedCharacterOrderRepositoryTest extends IntegrationTestCase
{
    private const int HAMMERHEAD_TYPE_ID = 2185;
    private const int SABRE_TYPE_ID = 22456;
    private const int RIFTER_TYPE_ID = 587;
    private const int BIGGER_THAN_AN_INTEGER_ORDER_ID = 6_350_000_001;

    private CachedCharacterOrderRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = self::getContainer()->get(CachedCharacterOrderRepository::class);
    }

    public function testFindsTheOrdersOfTheGivenCharactersAndTypesOnly(): void
    {
        $user = $this->createUser();
        $main = $this->createCharacter($user, 'Aria Solberg');
        $alt = $this->createCharacter($user, 'Brann Holt');
        $stranger = $this->createCharacter(null, 'Corin Vale');
        $this->persistOrder($main, self::BIGGER_THAN_AN_INTEGER_ORDER_ID, self::HAMMERHEAD_TYPE_ID);
        $this->persistOrder($alt, 2, self::SABRE_TYPE_ID);
        $this->persistOrder($main, 3, self::RIFTER_TYPE_ID);
        $this->persistOrder($stranger, 4, self::HAMMERHEAD_TYPE_ID);
        $this->em->flush();
        $this->em->clear();

        $orders = $this->repository->findByCharactersAndTypes(
            [$this->reload($main), $this->reload($alt)],
            [self::HAMMERHEAD_TYPE_ID, self::SABRE_TYPE_ID],
        );

        self::assertEqualsCanonicalizing([self::BIGGER_THAN_AN_INTEGER_ORDER_ID, 2], array_map(static fn (CachedCharacterOrder $order): int => $order->getOrderId(), $orders));
    }

    public function testFindsEveryOrderOfOneCharacterWithItsPosition(): void
    {
        $main = $this->createCharacter(null, 'Aria Solberg');
        $this->persistOrder($main, 1, self::HAMMERHEAD_TYPE_ID);
        $this->persistOrder($main, 2, self::SABRE_TYPE_ID);
        $this->em->flush();
        $this->em->clear();

        $orders = $this->repository->findByCharacter($this->reload($main));

        self::assertEqualsCanonicalizing(
            [[1, OrderPositionState::Undercut, 2, 900.0, 800.0], [2, OrderPositionState::Undercut, 2, 900.0, 800.0]],
            array_map(static fn (CachedCharacterOrder $order): array => [$order->getOrderId(), $order->getPosition()->state, $order->getPosition()->rank, $order->getPosition()->bestPrice, $order->getPosition()->suggestedPrice], $orders),
        );
    }

    public function testNoCharacterAndNoTypeGiveNoOrders(): void
    {
        self::assertSame([], $this->repository->findByCharactersAndTypes([], [self::HAMMERHEAD_TYPE_ID]));
        self::assertSame([], $this->repository->findByCharactersAndTypes([$this->createCharacter()], []));
    }

    private function persistOrder(Character $character, int $orderId, int $typeId): void
    {
        $this->em->persist(
            (new CachedCharacterOrder())
                ->setCharacter($character)
                ->setOrderId($orderId)
                ->setTypeId($typeId)
                ->setLocationId(60_003_760)
                ->setPrice(1000.0)
                ->setVolumeRemain(4)
                ->setVolumeTotal(10)
                ->setIssuedAt(new \DateTimeImmutable('2026-11-10 08:00:00'))
                ->setSyncedAt(new \DateTimeImmutable('2026-11-10 12:15:00'))
                ->setPosition(new OrderPosition(OrderPositionState::Undercut, 2, 900.0, null, 800.0))
                ->setUndercutSince(new \DateTimeImmutable('2026-11-10 12:00:00')),
        );
    }

    private function reload(Character $character): Character
    {
        return $this->em->find(Character::class, $character->getId());
    }
}
