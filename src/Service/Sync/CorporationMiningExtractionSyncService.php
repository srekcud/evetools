<?php

declare(strict_types=1);

namespace App\Service\Sync;

use App\Entity\CorporationMiningSyncState;

class CorporationMiningExtractionSyncService
{
    public function sync(int $corporationId): CorporationMiningSyncState
    {
        return (new CorporationMiningSyncState())->setCorporationId($corporationId);
    }
}
