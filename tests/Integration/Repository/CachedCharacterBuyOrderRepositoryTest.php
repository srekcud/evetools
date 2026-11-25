<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\CachedCharacterBuyOrder;
use App\Entity\Character;
use App\Repository\CachedCharacterBuyOrderRepository;
use App\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('issue-1393')]
final class CachedCharacterBuyOrderRepositoryTest extends IntegrationTestCase
{
    private const int ROBOTICS_TYPE_ID = 9848;
    private const int COOLANT_TYPE_ID = 9832;
    private const int CONSUMER_ELECTRONICS_TYPE_ID = 9836;
    private const int BIGGER_THAN_AN_INTEGER_ORDER_ID = 6_350_000_001;
    private const int BIGGER_THAN_AN_INTEGER_LOCATION_ID = 1_035_466_617_946;

    private CachedCharacterBuyOrderRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = self::getContainer()->get(CachedCharacterBuyOrderRepository::class);
    }

    public function testFindsTheBuyOrdersOfTheGivenCharactersAndTypesOnly(): void
    {
        $user = $this->createUser();
        $main = $this->createCharacter($user, 'Aria Solberg');
        $alt = $this->createCharacter($user, 'Brann Holt');
        $stranger = $this->createCharacter(null, 'Corin Vale');
        $this->persistBuyOrder($main, self::BIGGER_THAN_AN_INTEGER_ORDER_ID, self::ROBOTICS_TYPE_ID);
        $this->persistBuyOrder($alt, 2, self::COOLANT_TYPE_ID);
        $this->persistBuyOrder($main, 3, self::CONSUMER_ELECTRONICS_TYPE_ID);
        $this->persistBuyOrder($stranger, 4, self::ROBOTICS_TYPE_ID);
        $this->em->flush();
        $this->em->clear();

        $orders = $this->repository->findByCharactersAndTypes(
            [$this->reload($main), $this->reload($alt)],
            [self::ROBOTICS_TYPE_ID, self::COOLANT_TYPE_ID],
        );

        self::assertEqualsCanonicalizing([self::BIGGER_THAN_AN_INTEGER_ORDER_ID, 2], array_map(static fn (CachedCharacterBuyOrder $order): int => $order->getOrderId(), $orders));
    }

    public function testFindsEveryBuyOrderOfOneCharacterWithItsQuantityPriceAndLocation(): void
    {
        $main = $this->createCharacter(null, 'Aria Solberg');
        $this->persistBuyOrder($main, 1, self::ROBOTICS_TYPE_ID);
        $this->persistBuyOrder($main, 2, self::COOLANT_TYPE_ID);
        $this->em->flush();
        $this->em->clear();

        $orders = $this->repository->findByCharacter($this->reload($main));

        self::assertEqualsCanonicalizing(
            [[1, self::ROBOTICS_TYPE_ID, self::BIGGER_THAN_AN_INTEGER_LOCATION_ID, 4_800.0, 10_000, 12_000], [2, self::COOLANT_TYPE_ID, self::BIGGER_THAN_AN_INTEGER_LOCATION_ID, 4_800.0, 10_000, 12_000]],
            array_map(static fn (CachedCharacterBuyOrder $order): array => [$order->getOrderId(), $order->getTypeId(), $order->getLocationId(), $order->getPrice(), $order->getVolumeRemain(), $order->getVolumeTotal()], $orders),
        );
    }

    public function testNoCharacterAndNoTypeGiveNoBuyOrders(): void
    {
        self::assertSame([], $this->repository->findByCharactersAndTypes([], [self::ROBOTICS_TYPE_ID]));
        self::assertSame([], $this->repository->findByCharactersAndTypes([$this->createCharacter()], []));
    }

    private function persistBuyOrder(Character $character, int $orderId, int $typeId): void
    {
        $this->em->persist(
            (new CachedCharacterBuyOrder())
                ->setCharacter($character)
                ->setOrderId($orderId)
                ->setTypeId($typeId)
                ->setLocationId(self::BIGGER_THAN_AN_INTEGER_LOCATION_ID)
                ->setPrice(4_800.0)
                ->setVolumeRemain(10_000)
                ->setVolumeTotal(12_000)
                ->setIssuedAt(new \DateTimeImmutable('2026-11-10 08:00:00'))
                ->setSyncedAt(new \DateTimeImmutable('2026-11-10 12:15:00')),
        );
    }

    private function reload(Character $character): Character
    {
        return $this->em->find(Character::class, $character->getId());
    }
}
