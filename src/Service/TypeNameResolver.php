<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Sde\InvType;
use App\Repository\Sde\InvTypeRepository;

class TypeNameResolver
{
    public function __construct(
        private readonly InvTypeRepository $invTypeRepository,
    ) {
    }

    public function findName(int $typeId): ?string
    {
        return $this->invTypeRepository->find($typeId)?->getTypeName();
    }

    /**
     * @param int[] $typeIds
     * @return array<int, string>
     */
    public function findNames(array $typeIds): array
    {
        return array_map(
            static fn (InvType $type): string => $type->getTypeName(),
            $this->invTypeRepository->findByTypeIds($typeIds),
        );
    }
}
