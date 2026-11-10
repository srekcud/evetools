<?php

declare(strict_types=1);

namespace App\Service\MiningValuation;

use App\Entity\User;
use App\Enum\MarketSide;
use App\Enum\MiningValuationForm;
use App\Enum\MiningValuationMarket;
use App\Repository\MiningValuationProfileRepository;

final readonly class MiningValuationProfileReader
{
    public function __construct(private MiningValuationProfileRepository $profileRepository)
    {
    }

    public function read(User $user): MiningValuationSettings
    {
        return new MiningValuationSettings(MiningValuationMarket::Jita, MarketSide::Sell, MiningValuationForm::Raw, null, null, 0.0, false);
    }
}
