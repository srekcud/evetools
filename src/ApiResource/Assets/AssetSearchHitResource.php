<?php

declare(strict_types=1);

namespace App\ApiResource\Assets;

final class AssetSearchHitResource extends AssetBrowseItemResource
{
    public int $rootLocationId;

    /** @var list<int> */
    public array $containerPath = [];
}
