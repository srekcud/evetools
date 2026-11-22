<?php

declare(strict_types=1);

namespace App\Service\Industry\Project\Haul;

final class HaulLegDraft
{
    /** @var array<string, array{typeId: int, typeName: string, state: HaulLineState, source: HaulLineSource, quantity: int|null, readyAt: \DateTimeImmutable|null}> */
    private array $lines = [];

    /**
     * @param array{int, int} $arrivalRank
     * @param array{int, int} $departureRank
     */
    public function __construct(
        public readonly string $key,
        public readonly HaulPlace $departure,
        public readonly HaulPlace $arrival,
        public readonly array $arrivalRank,
        public readonly array $departureRank,
    ) {
    }

    public function add(int $typeId, string $typeName, HaulLineState $state, HaulLineSource $source, ?int $quantity, ?\DateTimeImmutable $readyAt): void
    {
        $lineKey = $typeId.'|'.$state->value.'|'.$source->value;
        if (!isset($this->lines[$lineKey])) {
            $this->lines[$lineKey] = ['typeId' => $typeId, 'typeName' => $typeName, 'state' => $state, 'source' => $source, 'quantity' => $quantity, 'readyAt' => $readyAt];

            return;
        }

        $knownQuantity = $this->lines[$lineKey]['quantity'];
        $this->lines[$lineKey]['quantity'] = null === $knownQuantity || null === $quantity ? null : $knownQuantity + $quantity;
    }

    /**
     * @return list<array{typeId: int, typeName: string, state: HaulLineState, source: HaulLineSource, quantity: int|null, readyAt: \DateTimeImmutable|null}>
     */
    public function lines(): array
    {
        return array_values($this->lines);
    }
}
