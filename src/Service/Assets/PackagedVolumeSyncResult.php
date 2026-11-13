<?php

declare(strict_types=1);

namespace App\Service\Assets;

final readonly class PackagedVolumeSyncResult
{
    public function __construct(
        public int $synced,
        public int $failed,
        public bool $aborted,
    ) {
    }
}
