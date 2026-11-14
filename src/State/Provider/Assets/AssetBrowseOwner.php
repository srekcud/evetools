<?php

declare(strict_types=1);

namespace App\State\Provider\Assets;

use App\Dto\Assets\AssetScope;
use App\Entity\Character;

final readonly class AssetBrowseOwner
{
    public function __construct(
        public AssetScope $scope,
        public ?Character $character,
        public ?int $corporationId,
    ) {
    }
}
