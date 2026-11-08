<?php

declare(strict_types=1);

namespace App\Service\MiningOperation;

use App\Repository\Sde\InvTypeRepository;

final readonly class MinedOreCompressedQuantities
{
    public function __construct(
        private InvTypeRepository $invTypeRepository,
    ) {
    }

    /**
     * @param array<int, int> $minedQuantitiesByTypeId
     * @return array{quantities: array<int, int>, unconvertedTypes: list<array{typeId: int, typeName: string|null}>}
     */
    public function of(array $minedQuantitiesByTypeId): array
    {
        return ['quantities' => [], 'unconvertedTypes' => []];
    }
}
