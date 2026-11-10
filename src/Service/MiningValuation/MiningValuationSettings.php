<?php

declare(strict_types=1);

namespace App\Service\MiningValuation;

use App\Enum\MarketSide;
use App\Enum\MiningValuationForm;
use App\Enum\MiningValuationMarket;

final readonly class MiningValuationSettings
{
    public function __construct(
        public MiningValuationMarket $market,
        public MarketSide $side,
        public MiningValuationForm $form,
        public ?int $structureId,
        public ?string $structureName,
        public float $exportTax,
        public bool $persisted,
    ) {
    }

    public static function defaults(): self
    {
        return new self(MiningValuationMarket::Jita, MarketSide::Buy, MiningValuationForm::Compressed, null, null, 0.0, false);
    }
}
