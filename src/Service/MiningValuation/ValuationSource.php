<?php

declare(strict_types=1);

namespace App\Service\MiningValuation;

use App\Enum\MarketSide;
use App\Enum\MiningValuationForm;
use App\Enum\MiningValuationMarket;

final readonly class ValuationSource
{
    public function __construct(
        public MiningValuationMarket $market,
        public MarketSide $side,
        public MiningValuationForm $form,
        public ?string $structureName,
    ) {
    }

    /**
     * @return array{market: string, side: string, form: string, structureName: string|null}
     */
    public function toArray(): array
    {
        return [
            'market' => $this->market->value,
            'side' => $this->side->value,
            'form' => $this->form->value,
            'structureName' => $this->structureName,
        ];
    }
}
