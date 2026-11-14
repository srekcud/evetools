<?php

declare(strict_types=1);

namespace App\ApiResource\Assets;

abstract class AssetChangesPayload
{
    public ?string $syncedAt = null;

    public ?string $previousSyncedAt = null;

    public int $total = 0;

    /** @var AssetChangeResource[] */
    public array $changes = [];
}
