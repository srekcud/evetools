<?php

declare(strict_types=1);

namespace App\ApiResource\Assets;

class SyncStatusResource
{
    public string $status;

    public string $message;

    public ?string $error = null;

    public ?bool $hasAccess = null;
}
