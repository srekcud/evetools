<?php

declare(strict_types=1);

namespace App\State\Provider\Assets;

use App\ApiResource\Assets\PinnedContainerResource;
use App\Service\Assets\PinnedContainerEntry;

final class PinnedContainerResourceMapper
{
    public static function of(PinnedContainerEntry $entry): PinnedContainerResource
    {
        $resource = new PinnedContainerResource();
        $resource->id = $entry->key;
        $resource->itemId = $entry->itemId;
        $resource->name = $entry->name;
        $resource->locationName = $entry->locationName;
        $resource->solarSystemName = $entry->solarSystemName;
        $resource->divisionName = $entry->divisionName;
        $resource->itemCount = $entry->itemCount;
        $resource->found = $entry->found;
        $resource->projectId = $entry->projectId;
        $resource->projectName = $entry->projectName;

        return $resource;
    }
}
