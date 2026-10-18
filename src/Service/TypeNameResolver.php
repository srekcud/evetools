<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\Sde\InvTypeRepository;

class TypeNameResolver
{
    public function __construct(
        private readonly InvTypeRepository $invTypeRepository,
    ) {
    }

    public function resolve(int $typeId): string
    {
        $type = $this->invTypeRepository->find($typeId);

        return $type?->getTypeName() ?? "Type #{$typeId}";
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
        return $this->resolveMany($typeIds);
    }

    /**
     * @param int[] $typeIds
     * @return array<int, string>
     */
    public function resolveMany(array $typeIds): array
    {
        if (empty($typeIds)) {
            return [];
        }

        $types = $this->invTypeRepository->findByTypeIds($typeIds);
        $map = [];

        foreach ($types as $typeId => $type) {
            $map[$typeId] = $type->getTypeName();
        }

        foreach ($typeIds as $id) {
            if (!isset($map[$id])) {
                $map[$id] = "Type #{$id}";
            }
        }

        return $map;
    }
}
