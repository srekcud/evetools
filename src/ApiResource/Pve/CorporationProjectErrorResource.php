<?php

declare(strict_types=1);

namespace App\ApiResource\Pve;

class CorporationProjectErrorResource
{
    public string $corporationName = '';

    public ?string $projectName = null;

    public ?string $characterName = null;

    public string $reason = '';

    public ?int $httpStatus = null;
}
