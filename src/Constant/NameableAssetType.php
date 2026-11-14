<?php

declare(strict_types=1);

namespace App\Constant;

final class NameableAssetType
{
    private const array CATEGORY_IDS = [
        EveConstants::SHIP_CATEGORY_ID,
        EveConstants::DEPLOYABLE_CATEGORY_ID,
        EveConstants::STARBASE_CATEGORY_ID,
        EveConstants::STRUCTURE_CATEGORY_ID,
    ];

    private const array GROUP_IDS = [
        EveConstants::CARGO_CONTAINER_GROUP_ID,
        EveConstants::SECURE_CARGO_CONTAINER_GROUP_ID,
        EveConstants::AUDIT_LOG_SECURE_CONTAINER_GROUP_ID,
        EveConstants::FREIGHT_CONTAINER_GROUP_ID,
    ];

    public static function accepts(?int $categoryId, ?int $groupId): bool
    {
        return \in_array($categoryId, self::CATEGORY_IDS, true)
            || \in_array($groupId, self::GROUP_IDS, true);
    }

    public static function mayHoldCustomName(
        ?bool $isSingleton,
        int $quantity,
        ?string $itemName,
        ?string $locationFlag,
    ): bool {
        return ($isSingleton ?? $quantity === 1)
            && $itemName === null
            && $locationFlag !== EveConstants::OFFICE_LOCATION_FLAG;
    }
}
