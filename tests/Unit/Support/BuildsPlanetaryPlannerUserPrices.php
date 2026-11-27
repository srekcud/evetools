<?php

declare(strict_types=1);

namespace App\Tests\Unit\Support;

use App\Entity\CachedWalletTransaction;
use App\Entity\Character;
use App\Repository\CachedWalletTransactionRepository;
use App\Repository\CharacterWalletReadingRepository;
use App\Repository\Sde\PlanetSchematicTypeRepository;
use App\Service\Planetary\PlanetaryPaidPriceCalculator;
use App\Service\Planetary\PlanetaryPlannerUserPrices;
use App\Service\Sync\WalletReadingStateReader;
use App\Service\StructureMarketService;
use App\Service\TypeNameResolver;

trait BuildsPlanetaryPlannerUserPrices
{
    private int $nextPlannerTransactionId = 1;

    /**
     * @param array<int, float|null> $hubSellPrices
     * @param list<CachedWalletTransaction> $transactions
     * @param list<int> $schematicInputTypeIds
     */
    private function plannerUserPrices(int $hubStructureId, array $hubSellPrices, bool $hubHasData, array $transactions, array $schematicInputTypeIds): PlanetaryPlannerUserPrices
    {
        $market = $this->createStub(StructureMarketService::class);
        $market->method('getLowestSellPrices')->willReturnCallback(
            static fn (int $structureId, array $typeIds): array => $structureId === $hubStructureId && $hubHasData
                ? array_combine($typeIds, array_map(static fn (int $typeId): ?float => $hubSellPrices[$typeId] ?? null, $typeIds))
                : array_fill_keys($typeIds, null),
        );
        $market->method('hasCachedData')->willReturnCallback(static fn (int $structureId): bool => $structureId === $hubStructureId && $hubHasData);

        $transactionRepository = $this->createStub(CachedWalletTransactionRepository::class);
        $transactionRepository->method('findBuysByCharactersSince')->willReturnCallback(
            static fn (array $ofCharacters, array $typeIds, \DateTimeImmutable $since): array => array_values(array_filter(
                $transactions,
                static fn (CachedWalletTransaction $transaction): bool => $transaction->isBuy()
                    && in_array($transaction->getCharacter(), $ofCharacters, true)
                    && in_array($transaction->getTypeId(), $typeIds, true)
                    && $transaction->getDate() >= $since,
            )),
        );
        $schematicTypeRepository = $this->createStub(PlanetSchematicTypeRepository::class);
        $schematicTypeRepository->method('getSchematicMap')->willReturn([
            1 => ['inputs' => array_fill_keys($schematicInputTypeIds, 1), 'output' => ['typeId' => 99_999_999, 'quantity' => 1]],
        ]);
        $readingRepository = $this->createStub(CharacterWalletReadingRepository::class);
        $readingRepository->method('findByCharacters')->willReturn([]);

        return new PlanetaryPlannerUserPrices(
            $market,
            new PlanetaryPaidPriceCalculator(
                $transactionRepository,
                $schematicTypeRepository,
                $this->createStub(TypeNameResolver::class),
                new WalletReadingStateReader($readingRepository),
            ),
        );
    }

    private function plannerPurchase(Character $character, int $typeId, int $quantity, float $unitPrice, string $date): CachedWalletTransaction
    {
        return (new CachedWalletTransaction())
            ->setCharacter($character)
            ->setTransactionId($this->nextPlannerTransactionId++)
            ->setTypeId($typeId)
            ->setQuantity($quantity)
            ->setUnitPrice($unitPrice)
            ->setIsBuy(true)
            ->setLocationId(60003760)
            ->setDate(new \DateTimeImmutable($date));
    }
}
