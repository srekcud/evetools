<?php

declare(strict_types=1);

namespace App\State\Provider\Industry;

use App\ApiResource\Industry\PersonalStockContainerResource;
use App\Service\Industry\Stock\PersonalStockContainerReading;

final class PersonalStockContainerResourceMapper
{
    public static function of(PersonalStockContainerReading $reading): PersonalStockContainerResource
    {
        $resource = new PersonalStockContainerResource();
        $resource->id = $reading->id;
        $resource->itemId = $reading->itemId;
        $resource->name = $reading->name;
        $resource->divisionName = $reading->divisionName;
        $resource->stationId = $reading->stationId;
        $resource->stationName = $reading->stationName;
        $resource->status = $reading->status;
        $resource->unknownReason = $reading->unknownReason;
        $resource->itemCount = $reading->itemCount;

        return $resource;
    }
}
