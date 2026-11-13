<?php

declare(strict_types=1);

namespace App\State\Provider\Market;

use App\ApiResource\Market\MarketAlertResource;
use App\Entity\MarketPriceAlert;
use App\Entity\User;

class MarketAlertResourceMapper
{
    public static function toResource(MarketPriceAlert $alert, ?float $currentPrice, ?string $structureName = null): MarketAlertResource
    {
        $resource = new MarketAlertResource();
        $resource->id = $alert->getId()?->toRfc4122() ?? '';
        $resource->typeId = $alert->getTypeId();
        $resource->typeName = $alert->getTypeName();
        $resource->direction = $alert->getDirection()->value;
        $resource->threshold = $alert->getThreshold();
        $resource->priceSource = $alert->getPriceSource()->value;
        $resource->structureId = $alert->getStructureId();
        $resource->structureName = $structureName;
        $resource->status = $alert->getStatus()->value;
        $resource->currentPrice = $currentPrice;
        $resource->triggeredAt = $alert->getTriggeredAt()?->format('c');
        $resource->triggeredPrice = $alert->getTriggeredPrice();
        $resource->createdAt = $alert->getCreatedAt()->format('c');

        return $resource;
    }

    public static function structureNameOf(User $owner, MarketPriceAlert $alert): ?string
    {
        $structureId = $alert->getStructureId();
        if ($structureId === null) {
            return null;
        }

        if ($owner->getPreferredMarketStructureId() === $structureId) {
            return $owner->getPreferredMarketStructureName();
        }

        foreach ($owner->getMarketStructures() as $followedStructure) {
            if ($followedStructure['id'] === $structureId) {
                return $followedStructure['name'];
            }
        }

        return null;
    }
}
