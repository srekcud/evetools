<?php

declare(strict_types=1);

namespace App\ApiResource\Input\Industry;

class UpdateBlacklistInput
{
    /** @var int[]|null */
    public ?array $groupIds = null;

    /** @var int[]|null */
    public ?array $typeIds = null;
}
