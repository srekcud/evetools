<?php

declare(strict_types=1);

namespace App\ApiResource\Admin;

class AccessCheckResource
{
    public bool $hasAccess = false;

    public ?string $characterName = null;
}
