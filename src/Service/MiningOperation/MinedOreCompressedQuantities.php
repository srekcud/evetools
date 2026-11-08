<?php

declare(strict_types=1);

namespace App\Service\MiningOperation;

use App\Entity\Sde\InvType;
use App\Repository\Sde\InvTypeRepository;

final readonly class MinedOreCompressedQuantities
{
    private const string COMPRESSED_NAME_PREFIX = 'Compressed ';

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
        $positiveQuantities = array_filter($minedQuantitiesByTypeId, static fn (int $quantity): bool => $quantity > 0);
        if ($positiveQuantities === []) {
            return ['quantities' => [], 'unconvertedTypes' => []];
        }

        $minedTypes = $this->invTypeRepository->findByTypeIds(array_keys($positiveQuantities));
        $compressedTypes = $this->invTypeRepository->findByNames(array_values(array_map(
            static fn (InvType $minedType): string => self::COMPRESSED_NAME_PREFIX . $minedType->getTypeName(),
            $minedTypes,
        )));

        $quantities = [];
        $unconvertedTypes = [];
        foreach ($positiveQuantities as $typeId => $minedQuantity) {
            $minedType = $minedTypes[$typeId] ?? null;
            $compressedType = $minedType === null ? null : ($compressedTypes[self::COMPRESSED_NAME_PREFIX . $minedType->getTypeName()] ?? null);
            $minedPortionSize = $minedType?->getPortionSize();
            $compressedPortionSize = $compressedType?->getPortionSize();

            if ($compressedType === null || $minedPortionSize === null || $minedPortionSize <= 0 || $compressedPortionSize === null || $compressedPortionSize <= 0) {
                $unconvertedTypes[] = ['typeId' => $typeId, 'typeName' => $minedType?->getTypeName()];
                continue;
            }

            $compressedQuantity = intdiv($minedQuantity, $minedPortionSize) * $compressedPortionSize;
            if ($compressedQuantity > 0) {
                $quantities[$compressedType->getTypeId()] = ($quantities[$compressedType->getTypeId()] ?? 0) + $compressedQuantity;
            }
        }

        return ['quantities' => $quantities, 'unconvertedTypes' => $unconvertedTypes];
    }
}
