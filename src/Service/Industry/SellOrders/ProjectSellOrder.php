<?php

declare(strict_types=1);

namespace App\Service\Industry\SellOrders;

use App\Enum\OrderPositionState;
use App\Enum\OrderPositionUnknownReason;

final readonly class ProjectSellOrder
{
    public function __construct(
        public int $orderId,
        public string $characterName,
        public int $typeId,
        public string $typeName,
        public int $volumeRemain,
        public int $locationId,
        public ?string $marketName,
        public float $price,
        public ?float $bestPrice,
        public ?int $rank,
        public OrderPositionState $state,
        public ?OrderPositionUnknownReason $unknownReason,
        public ?\DateTimeImmutable $undercutSince,
        public ?float $suggestedPrice,
    ) {
    }

    /**
     * @return array{
     *     orderId: int,
     *     characterName: string,
     *     typeId: int,
     *     typeName: string,
     *     volumeRemain: int,
     *     locationId: int,
     *     marketName: string|null,
     *     price: float,
     *     bestPrice: float|null,
     *     rank: int|null,
     *     state: string,
     *     unknownReason: string|null,
     *     undercutSince: string|null,
     *     suggestedPrice: float|null,
     * }
     */
    public function toArray(): array
    {
        return [
            'orderId' => $this->orderId,
            'characterName' => $this->characterName,
            'typeId' => $this->typeId,
            'typeName' => $this->typeName,
            'volumeRemain' => $this->volumeRemain,
            'locationId' => $this->locationId,
            'marketName' => $this->marketName,
            'price' => $this->price,
            'bestPrice' => $this->bestPrice,
            'rank' => $this->rank,
            'state' => $this->state->value,
            'unknownReason' => $this->unknownReason?->value,
            'undercutSince' => $this->undercutSince?->format(\DATE_ATOM),
            'suggestedPrice' => $this->suggestedPrice,
        ];
    }
}
