<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\GroupIndustry;

use App\Entity\CachedWalletTransaction;
use App\Entity\Character;
use App\Entity\GroupIndustryProject;
use App\Entity\GroupIndustryProjectItem;
use App\Entity\GroupIndustrySale;
use App\Enum\GroupProjectStatus;
use App\Repository\CachedWalletTransactionRepository;
use PHPUnit\Framework\MockObject\Stub;
use Symfony\Component\Uid\Uuid;

trait SabreBatchWalletTransactions
{
    private const int SABRE_TYPE_ID = 22456;
    private const int ISHTAR_TYPE_ID = 12005;
    private const string SABRE_BATCH_CREATED_AT = '2026-10-20T10:00:00+00:00';
    private const int JITA_STATION_ID = 60003760;
    private const int AMARR_STATION_ID = 60008494;
    private const int DODIXIE_STATION_ID = 60011866;
    private const int RENS_STATION_ID = 60004588;
    private const int PERIMETER_STRUCTURE_ID = 1035466617946;

    private CachedWalletTransaction $t1;
    private CachedWalletTransaction $t2;
    private CachedWalletTransaction $t3;
    private CachedWalletTransaction $t4;
    private CachedWalletTransaction $t5;
    private CachedWalletTransaction $t6;
    private CachedWalletTransaction $t7;
    private GroupIndustrySale $manualSaleV;
    private GroupIndustrySale $saleOfAnotherProjectLinkedToT6;

    private array $walletTransactions = [];

    private function createSabreBatchWalletTransactions(): void
    {
        (new \ReflectionProperty(GroupIndustryProject::class, 'createdAt'))->setValue($this->sabreBatch, new \DateTimeImmutable(self::SABRE_BATCH_CREATED_AT));
        $this->sabreBatch->setStatus(GroupProjectStatus::Selling);
        $this->addSabreBatchItem(self::SABRE_TYPE_ID, 'Sabre', 10);
        $this->manualSaleV = $this->addSabreBatchSale(0, 3, 61_250_000.0);

        $this->t1 = $this->walletTransaction(6_100_000_001, $this->oMain, false, self::SABRE_TYPE_ID, 3, 61_250_000.0, '2026-10-24T21:05:00+00:00', self::JITA_STATION_ID);
        $this->t2 = $this->walletTransaction(6_100_000_002, $this->oMain, true, self::SABRE_TYPE_ID, 5, 58_000_000.0, '2026-10-22T12:00:00+00:00', self::JITA_STATION_ID);
        $this->t3 = $this->walletTransaction(6_100_000_003, $this->oAlt, false, self::ISHTAR_TYPE_ID, 1, 310_000_000.0, '2026-10-23T15:30:00+00:00', self::AMARR_STATION_ID);
        $this->t4 = $this->walletTransaction(6_100_000_004, $this->oMain, false, self::SABRE_TYPE_ID, 1, 60_000_000.0, '2026-10-19T09:00:00+00:00', self::DODIXIE_STATION_ID);
        $this->t5 = $this->walletTransaction(6_100_000_005, $this->aMain, false, self::SABRE_TYPE_ID, 2, 61_000_000.0, '2026-10-25T18:00:00+00:00', self::PERIMETER_STRUCTURE_ID);
        $this->t6 = $this->walletTransaction(6_100_000_006, $this->oAlt, false, self::SABRE_TYPE_ID, 2, 60_500_000.0, '2026-10-25T20:00:00+00:00', self::JITA_STATION_ID);
        $this->t7 = $this->walletTransaction(6_100_000_007, $this->oAlt, false, self::SABRE_TYPE_ID, 12, 60_000_000.0, '2026-10-26T11:45:00+00:00', self::RENS_STATION_ID);

        $cutlassBatch = (new GroupIndustryProject())->setOwner($this->sabreBatchOwner)->setName('Cutlass batch')->setStatus(GroupProjectStatus::Selling);
        $this->saleOfAnotherProjectLinkedToT6 = $this->saleOf($cutlassBatch, self::SABRE_TYPE_ID, 2, 60_500_000.0);
    }

    private function addSabreBatchItem(int $typeId, string $typeName, int $meLevel): GroupIndustryProjectItem
    {
        $item = (new GroupIndustryProjectItem())->setTypeId($typeId)->setTypeName($typeName)->setMeLevel($meLevel)->setRuns(1);
        (new \ReflectionProperty(GroupIndustryProjectItem::class, 'id'))->setValue($item, Uuid::v4());
        $this->sabreBatch->addItem($item);

        return $item;
    }

    private function addSabreBatchSale(int $typeId, int $quantity, float $unitPrice): GroupIndustrySale
    {
        return $this->saleOf($this->sabreBatch, $typeId, $quantity, $unitPrice);
    }

    private function saleOf(GroupIndustryProject $project, int $typeId, int $quantity, float $unitPrice): GroupIndustrySale
    {
        $sale = (new GroupIndustrySale())
            ->setTypeId($typeId)
            ->setTypeName('Sabre')
            ->setQuantity($quantity)
            ->setUnitPrice($unitPrice)
            ->recalculateTotalPrice()
            ->setSoldAt(new \DateTimeImmutable('2026-10-24T22:00:00+00:00'))
            ->setRecordedBy($this->sabreBatchOwner);
        (new \ReflectionProperty(GroupIndustrySale::class, 'id'))->setValue($sale, Uuid::v4());
        $project->addSale($sale);

        return $sale;
    }

    private function walletTransaction(
        int $transactionId,
        Character $character,
        bool $isBuy,
        int $typeId,
        int $quantity,
        float $unitPrice,
        string $date,
        int $locationId,
    ): CachedWalletTransaction {
        $transaction = (new CachedWalletTransaction())
            ->setCharacter($character)
            ->setTransactionId($transactionId)
            ->setIsBuy($isBuy)
            ->setTypeId($typeId)
            ->setQuantity($quantity)
            ->setUnitPrice($unitPrice)
            ->setDate(new \DateTimeImmutable($date))
            ->setLocationId($locationId)
            ->setClientId(2_119_000_000 + $transactionId % 1000);
        (new \ReflectionProperty(CachedWalletTransaction::class, 'id'))->setValue($transaction, Uuid::v4());
        $this->walletTransactions[] = $transaction;

        return $transaction;
    }

    private function isLinkedToASale(CachedWalletTransaction $transaction): bool
    {
        return $this->saleLinkedTo($transaction) !== null;
    }

    private function saleLinkedTo(CachedWalletTransaction $transaction): ?GroupIndustrySale
    {
        if ($transaction === $this->t6) {
            return $this->saleOfAnotherProjectLinkedToT6;
        }

        foreach ($this->sabreBatch->getSales() as $sale) {
            if ($sale->getWalletTransaction() === $transaction) {
                return $sale;
            }
        }

        return null;
    }

    private function walletTransactionRepository(): CachedWalletTransactionRepository&Stub
    {
        $transactionRepository = $this->createStub(CachedWalletTransactionRepository::class);
        $transactionRepository->method('find')->willReturnCallback(
            function (Uuid|string $id): ?CachedWalletTransaction {
                $wanted = $id instanceof Uuid ? $id : Uuid::fromString($id);
                foreach ($this->walletTransactions as $transaction) {
                    if ($wanted->equals($transaction->getId())) {
                        return $transaction;
                    }
                }

                return null;
            },
        );
        $transactionRepository->method('findUnlinkedSaleTransactions')->willReturnCallback(
            function (array $characters, array $typeIds, \DateTimeImmutable $since): array {
                $unlinkedSales = array_values(array_filter(
                    $this->walletTransactions,
                    fn (CachedWalletTransaction $transaction): bool => in_array($transaction->getCharacter(), $characters, true)
                        && !$transaction->isBuy()
                        && in_array($transaction->getTypeId(), $typeIds, true)
                        && $transaction->getDate() >= $since
                        && !$this->isLinkedToASale($transaction),
                ));
                usort($unlinkedSales, static fn (CachedWalletTransaction $a, CachedWalletTransaction $b): int => $b->getDate() <=> $a->getDate());

                return $unlinkedSales;
            },
        );

        return $transactionRepository;
    }
}
