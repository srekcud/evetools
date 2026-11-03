<?php

declare(strict_types=1);

namespace App\Service\Assets;

use App\Entity\CachedAsset;

final readonly class CorporationContainerContents
{
    /**
     * @param list<CachedAsset> $visibleAssets
     *
     * @return array<int, int>|null
     */
    public function quantitiesIn(array $visibleAssets, string $containerName): ?array
    {
        return [];
    }
}
