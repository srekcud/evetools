<?php

declare(strict_types=1);

namespace App\ApiResource\Assets;

class AssetItemResource
{
    public string $id;

    public int $itemId;

    public int $typeId;

    public string $typeName;

    public ?int $categoryId = null;

    public int $quantity;

    public int $locationId;

    public ?string $locationName = null;

    public string $locationType;

    public string $locationFlag;

    public ?int $solarSystemId = null;

    public ?string $solarSystemName = null;

    public ?string $itemName = null;

    public ?string $divisionName = null;

    public string $cachedAt;
}
