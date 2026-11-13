<?php

declare(strict_types=1);

namespace App\ApiResource\Assets;

final readonly class AssetBlueprintResource
{
    public const string ORIGINAL = 'original';
    public const string COPY = 'copy';
    public const string NOT_SYNCED = 'not_synced';

    public function __construct(
        public string $status,
        public ?int $runs,
        public ?int $materialEfficiency,
        public ?int $timeEfficiency,
    ) {
    }
}
