<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

use App\Entity\CachedAsset;

final readonly class ProjectContainerScope
{
    /**
     * @param list<int> $containerItemIds item ids of the project containers and of everything stored in them
     * @param array<int, CachedAsset> $visibleAssetsByItemId corporation assets visible to the project owner
     * @param list<string> $missingContainerNames declared container names without any visible container
     */
    public function __construct(
        public ?string $unknownReason,
        public array $containerItemIds = [],
        public array $visibleAssetsByItemId = [],
        public array $missingContainerNames = [],
    ) {
    }

    public function isKnown(): bool
    {
        return $this->unknownReason === null;
    }

    public function contains(int $itemId): bool
    {
        return \in_array($itemId, $this->containerItemIds, true);
    }
}
