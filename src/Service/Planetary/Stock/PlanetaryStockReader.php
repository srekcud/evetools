<?php

declare(strict_types=1);

namespace App\Service\Planetary\Stock;

use App\Entity\User;
use App\Repository\AssetBrowseRepository;
use App\Repository\CachedCharacterBuyOrderRepository;
use App\Repository\PlanetaryColonyRepository;
use App\Repository\Sde\InvTypeRepository;
use App\Service\Industry\Stock\PersonalStockReader;
use App\Service\Planetary\PlanetaryInputNeedsCalculator;

final readonly class PlanetaryStockReader
{
    public function __construct(
        private PlanetaryColonyRepository $colonyRepository,
        private PlanetaryInputNeedsCalculator $inputNeedsCalculator,
        private InvTypeRepository $invTypeRepository,
        private AssetBrowseRepository $assetBrowseRepository,
        private PersonalStockReader $personalStockReader,
        private CachedCharacterBuyOrderRepository $buyOrderRepository,
        private PlanetaryStockCalculator $calculator,
    ) {
    }

    public function read(User $user, PlanetaryStockSettings $settings): PlanetaryStock
    {
        return new PlanetaryStock([], null, false);
    }
}
