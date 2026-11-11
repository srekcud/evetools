<?php

declare(strict_types=1);

namespace App\Service\Sync;

use App\Constant\EveConstants;
use App\Dto\CharacterSellOrder;
use App\Entity\CachedCharacterOrder;
use App\Entity\Character;
use App\Entity\User;
use App\Repository\CachedCharacterOrderRepository;
use App\Service\ESI\CharacterOrdersService;
use App\Service\Industry\SellOrders\SellOrderBooks;
use App\Service\Industry\SellOrders\UndercutAssessor;
use App\Service\Industry\SellOrders\UndercutSince;
use App\Service\Industry\SellOrders\UserSellableProductTypes;
use App\Service\Mercure\MercurePublisherService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;

class CharacterOrderSyncService
{
    private const string SYNC_TYPE = 'character-orders';

    public function __construct(
        private readonly CharacterOrdersService $ordersService,
        private readonly CachedCharacterOrderRepository $orderRepository,
        private readonly UserSellableProductTypes $sellableProductTypes,
        private readonly SellOrderBooks $books,
        private readonly UndercutAssessor $assessor,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
        private readonly MercurePublisherService $mercurePublisher,
    ) {
    }

    public function syncUserOrders(User $user): void
    {
        $userId = $user->getId()?->toRfc4122() ?? throw new \LogicException('Cannot sync character orders of a user without id');

        $this->mercurePublisher->syncStarted($userId, self::SYNC_TYPE, 'Fetching sell orders...');

        try {
            $syncedCharacters = $this->syncCharacters($user);
            $failures = $syncedCharacters['failures'];

            if ($failures !== [] && $syncedCharacters['synced'] === 0) {
                $this->mercurePublisher->syncError($userId, self::SYNC_TYPE, 'No sell orders could be synced', ['failedCharacters' => $failures]);

                return;
            }

            $this->mercurePublisher->syncCompleted(
                $userId,
                self::SYNC_TYPE,
                \sprintf('%d characters synced', $syncedCharacters['synced']),
                ['characters' => $syncedCharacters['synced'], 'failedCharacters' => $failures],
            );
        } catch (\Throwable $e) {
            $this->mercurePublisher->syncError($userId, self::SYNC_TYPE, $e->getMessage());
            throw $e;
        }
    }

    /**
     * @return array{synced: int, failures: list<array<string, mixed>>}
     */
    private function syncCharacters(User $user): array
    {
        $characters = array_values(array_filter($user->getCharacters()->getValues(), $this->canReadOrders(...)));
        $productTypeIds = $this->sellableProductTypes->of($user);

        if ($productTypeIds === []) {
            foreach ($characters as $character) {
                $this->removeOrdersOf($character, []);
            }
            $this->entityManager->flush();

            return ['synced' => \count($characters), 'failures' => []];
        }

        $ordersByCharacter = [];
        $failures = [];
        foreach ($characters as $character) {
            try {
                $ordersByCharacter[$character->getName()] = [$character, $this->productOrdersOf($character, $productTypeIds)];
            } catch (\Throwable $e) {
                $failure = CharacterSyncFailure::fromThrowable($character, $e);
                $this->logger->warning('Failed to read character sell orders', $failure->logContext($e->getMessage()));
                $failures[] = $failure->toArray();
            }
        }

        $jitaTypeIds = $this->jitaTypeIdsOf($ordersByCharacter);
        if ($jitaTypeIds !== []) {
            $this->books->refreshJita($jitaTypeIds);
        }

        $now = $this->clock->now();
        foreach ($ordersByCharacter as [$character, $orders]) {
            $this->replaceOrdersOf($character, $orders, $now);
        }
        $this->entityManager->flush();

        return ['synced' => \count($ordersByCharacter), 'failures' => $failures];
    }

    private function canReadOrders(Character $character): bool
    {
        return $character->isAuthValid() && $character->getEveToken()?->hasScope(EveConstants::CHARACTER_ORDERS_SCOPE) === true;
    }

    /**
     * @param list<int> $productTypeIds
     *
     * @return list<CharacterSellOrder>
     */
    private function productOrdersOf(Character $character, array $productTypeIds): array
    {
        return array_values(array_filter(
            $this->ordersService->getSellOrders($character),
            static fn (CharacterSellOrder $order): bool => \in_array($order->typeId, $productTypeIds, true),
        ));
    }

    /**
     * @param array<string, array{Character, list<CharacterSellOrder>}> $ordersByCharacter
     *
     * @return list<int>
     */
    private function jitaTypeIdsOf(array $ordersByCharacter): array
    {
        $typeIds = [];
        foreach ($ordersByCharacter as [, $orders]) {
            foreach ($orders as $order) {
                if ($order->locationId === EveConstants::JITA_STATION_ID) {
                    $typeIds[$order->typeId] = $order->typeId;
                }
            }
        }
        sort($typeIds);

        return $typeIds;
    }

    /**
     * @param list<CharacterSellOrder> $orders
     */
    private function replaceOrdersOf(Character $character, array $orders, \DateTimeImmutable $now): void
    {
        $existingByOrderId = $this->removeOrdersOf($character, $orders);

        foreach ($orders as $order) {
            $row = $existingByOrderId[$order->orderId] ?? null;
            if ($row === null) {
                $row = (new CachedCharacterOrder())->setCharacter($character)->setOrderId($order->orderId);
                $this->entityManager->persist($row);
            }

            $position = $this->assessor->assess($order->price, $this->books->of($order->locationId, $order->typeId));
            $row->setTypeId($order->typeId)
                ->setLocationId($order->locationId)
                ->setPrice($order->price)
                ->setVolumeRemain($order->volumeRemain)
                ->setVolumeTotal($order->volumeTotal)
                ->setIssuedAt($order->issuedAt)
                ->setSyncedAt($now)
                ->setPosition($position)
                ->setUndercutSince(UndercutSince::resolve($row->getUndercutSince(), $position->state, $now));
        }
    }

    /**
     * @param list<CharacterSellOrder> $keptOrders
     *
     * @return array<int, CachedCharacterOrder> the rows kept, by order id
     */
    private function removeOrdersOf(Character $character, array $keptOrders): array
    {
        $keptOrderIds = array_map(static fn (CharacterSellOrder $order): int => $order->orderId, $keptOrders);

        $kept = [];
        foreach ($this->orderRepository->findByCharacter($character) as $row) {
            if (\in_array($row->getOrderId(), $keptOrderIds, true)) {
                $kept[$row->getOrderId()] = $row;
            } else {
                $this->entityManager->remove($row);
            }
        }

        return $kept;
    }
}
