<?php

declare(strict_types=1);

namespace App\ApiResource\Input\Industry\Project;

use Symfony\Component\Validator\Constraints as Assert;

class ConfirmTransferReceivedInput
{
    #[Assert\NotNull]
    public ?bool $received = null;
}
