<?php

declare(strict_types=1);

namespace App\State\Provider\Market;

use App\ApiResource\Market\MarketAlertResource;
use App\Entity\MarketPriceAlert;

class MarketAlertResourceMapper
{
    public static function toResource(MarketPriceAlert $alert, ?float $currentPrice): MarketAlertResource
    {
        $resource = new MarketAlertResource();
        $resource->id = $alert->getId()?->toRfc4122() ?? '';
        $resource->typeId = $alert->getTypeId();
        $resource->typeName = $alert->getTypeName();
        $resource->direction = $alert->getDirection()->value;
        $resource->threshold = $alert->getThreshold();
        $resource->priceSource = $alert->getPriceSource()->value;
        $resource->status = $alert->getStatus()->value;
        $resource->currentPrice = $currentPrice;
        $resource->triggeredAt = $alert->getTriggeredAt()?->format('c');
        $resource->triggeredPrice = $alert->getTriggeredPrice();
        $resource->createdAt = $alert->getCreatedAt()->format('c');

        return $resource;
    }
}
