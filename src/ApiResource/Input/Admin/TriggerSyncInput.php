<?php

declare(strict_types=1);

namespace App\ApiResource\Input\Admin;

use Symfony\Component\Validator\Constraints as Assert;

class TriggerSyncInput
{
    #[Assert\NotBlank]
    public string $type = '';
}
