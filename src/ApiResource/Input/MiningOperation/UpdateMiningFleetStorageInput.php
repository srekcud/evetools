<?php

declare(strict_types=1);

namespace App\ApiResource\Input\MiningOperation;

use Symfony\Component\Validator\Constraints as Assert;

class UpdateMiningFleetStorageInput
{
    #[Assert\Uuid]
    public ?string $recipientMemberId;

    #[Assert\Length(min: 1, max: 100)]
    public ?string $containerName;
}
