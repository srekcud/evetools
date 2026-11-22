<?php

declare(strict_types=1);

namespace App\Service\Industry\Project\Haul;

final class HaulSupply
{
    /**
     * @param array{int, int} $departureRank
     */
    public function __construct(
        public readonly ?int $locationId,
        public readonly HaulPlace $departure,
        public readonly array $departureRank,
        public readonly HaulLineSource $source,
        private int $ready,
        private int $inProduction,
    ) {
    }

    public function available(): int
    {
        return $this->ready + $this->inProduction;
    }

    /**
     * @return list<array{HaulLineState, int}>
     */
    public function take(int $quantity): array
    {
        $takenReady = min($quantity, $this->ready);
        $takenInProduction = min($quantity - $takenReady, $this->inProduction);
        $this->ready -= $takenReady;
        $this->inProduction -= $takenInProduction;

        return array_values(array_filter([
            [HaulLineState::Ready, $takenReady],
            [HaulLineState::InProduction, $takenInProduction],
        ], static fn (array $taken): bool => $taken[1] > 0));
    }
}
