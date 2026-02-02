<?php

declare(strict_types=1);

namespace App\ApiResource\Ansiblex;

class AnsiblexSyncResultResource
{
    public string $status;

    public ?string $message = null;

    public ?string $warning = null;

    public ?string $error = null;

    public ?string $reason = null;

    /** @var array<string, int>|null */
    public ?array $stats = null;
}
