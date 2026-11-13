<?php

declare(strict_types=1);

namespace App\Tests\Unit\Support;

use App\Entity\Sde\InvCategory;
use App\Entity\Sde\InvGroup;
use App\Entity\Sde\InvType;
use App\Repository\Sde\InvTypeRepository;
use PHPUnit\Framework\MockObject\Stub;

trait StubsSdeTypeClassification
{
    private function createSdeClassification(array $categoryAndGroupByTypeId): InvTypeRepository&Stub
    {
        $repository = $this->createStub(InvTypeRepository::class);
        $repository->method('findByTypeIds')->willReturnCallback(
            static function (array $typeIds) use ($categoryAndGroupByTypeId): array {
                $types = [];
                foreach ($typeIds as $typeId) {
                    if (!isset($categoryAndGroupByTypeId[$typeId])) {
                        continue;
                    }

                    [$categoryId, $groupId] = $categoryAndGroupByTypeId[$typeId];
                    $category = (new InvCategory())->setCategoryId($categoryId);
                    $group = (new InvGroup())->setGroupId($groupId)->setCategory($category);
                    $types[$typeId] = (new InvType())->setTypeId($typeId)->setGroup($group);
                }

                return $types;
            },
        );

        return $repository;
    }

    private static function cargoContainerClassification(): array
    {
        return [2, 12];
    }

    private static function shipClassification(): array
    {
        return [6, 25];
    }

    private static function mineralClassification(): array
    {
        return [4, 18];
    }

    private static function blueprintClassification(): array
    {
        return [9, 105];
    }

    private static function moduleClassification(): array
    {
        return [7, 55];
    }

    private static function starbaseClassification(): array
    {
        return [23, 365];
    }

    private static function structureClassification(): array
    {
        return [65, 1657];
    }
}
