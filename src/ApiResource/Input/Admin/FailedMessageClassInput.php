<?php

declare(strict_types=1);

namespace App\ApiResource\Input\Admin;

use Symfony\Component\Validator\Constraints as Assert;

class FailedMessageClassInput
{
    #[Assert\NotBlank]
    public string $messageClass = '';
}
