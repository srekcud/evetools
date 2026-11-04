<?php

declare(strict_types=1);

namespace App\Service\Sync;

use App\Entity\MoonTaxSyncState;

class MoonTaxSyncService
{
    public const string SYNC_TYPE = 'moon-tax';

    public function sync(int $corporationId): MoonTaxSyncState
    {
        return (new MoonTaxSyncState())->setCorporationId($corporationId);
    }
}
