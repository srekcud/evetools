<?php

declare(strict_types=1);

namespace App\Service\Planetary;

use App\Entity\CachedWalletTransaction;
use App\Entity\Character;
use App\Entity\User;
use App\Repository\CachedWalletTransactionRepository;
use App\Repository\Sde\PlanetSchematicTypeRepository;
use App\Service\Sync\WalletReadingStateReader;
use App\Service\TypeNameResolver;

final readonly class PlanetaryPaidPriceCalculator
{
    public const int PAID_PRICE_DAYS = 90;

    public function __construct(
        private CachedWalletTransactionRepository $transactionRepository,
        private PlanetSchematicTypeRepository $schematicTypeRepository,
        private TypeNameResolver $typeNameResolver,
        private WalletReadingStateReader $walletReadingStateReader,
    ) {
    }

    /**
     * @return array{
     *     days: int,
     *     since: string,
     *     prices: list<array{typeId: int, typeName: string|null, paidPrice: float|null, quantity: int, purchaseCount: int}>,
     *     unsyncedWallets: list<array{characterId: int, characterName: string, status: string}>
     * }
     */
    public function calculate(User $user, \DateTimeImmutable $now): array
    {
        $since = $now->modify(sprintf('-%d days', self::PAID_PRICE_DAYS));
        $characters = array_values($user->getCharacters()->toArray());
        $inputTypeIds = $this->inputTypeIds();

        $purchasesByType = [];
        foreach ($this->transactionRepository->findBuysByCharactersSince($characters, $inputTypeIds, $since) as $purchase) {
            $purchasesByType[$purchase->getTypeId()][] = $purchase;
        }

        $prices = [];
        foreach ($inputTypeIds as $typeId) {
            $purchases = $purchasesByType[$typeId] ?? [];
            $quantity = array_sum(array_map(static fn (CachedWalletTransaction $purchase): int => $purchase->getQuantity(), $purchases));
            $cost = array_sum(array_map(static fn (CachedWalletTransaction $purchase): float => $purchase->getQuantity() * $purchase->getUnitPrice(), $purchases));
            $prices[] = [
                'typeId' => $typeId,
                'typeName' => $this->typeNameResolver->findName($typeId),
                'paidPrice' => $quantity > 0 ? round($cost / $quantity, 2) : null,
                'quantity' => $quantity,
                'purchaseCount' => count($purchases),
            ];
        }

        return [
            'days' => self::PAID_PRICE_DAYS,
            'since' => $since->setTimezone(new \DateTimeZone('UTC'))->format(\DATE_ATOM),
            'prices' => $prices,
            'unsyncedWallets' => $this->unsyncedWallets($user, $characters),
        ];
    }

    /**
     * @return list<int>
     */
    private function inputTypeIds(): array
    {
        $typeIds = [];
        foreach ($this->schematicTypeRepository->getSchematicMap() as $schematic) {
            foreach (array_keys($schematic['inputs']) as $typeId) {
                $typeIds[$typeId] = $typeId;
            }
        }
        sort($typeIds);

        return $typeIds;
    }

    /**
     * @param list<Character> $characters
     * @return list<array{characterId: int, characterName: string, status: string}>
     */
    private function unsyncedWallets(User $user, array $characters): array
    {
        $statuses = $this->walletReadingStateReader->unsyncedStatusByEveCharacterId($user);

        $unsynced = [];
        foreach ($characters as $character) {
            $eveCharacterId = $character->getEveCharacterId();
            if (isset($statuses[$eveCharacterId])) {
                $unsynced[] = ['characterId' => $eveCharacterId, 'characterName' => $character->getName(), 'status' => $statuses[$eveCharacterId]];
            }
        }

        return $unsynced;
    }
}
