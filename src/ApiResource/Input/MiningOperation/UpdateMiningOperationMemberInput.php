<?php

declare(strict_types=1);

namespace App\ApiResource\Input\MiningOperation;

class UpdateMiningOperationMemberInput
{
    public ?string $role = null;

    public ?string $status = null;
}
