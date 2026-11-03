<?php

declare(strict_types=1);

namespace App\ApiResource\Input\MiningOperation;

class UpdateMiningFleetStorageInput
{
    public ?string $recipientMemberId;

    public ?string $containerName;
}
