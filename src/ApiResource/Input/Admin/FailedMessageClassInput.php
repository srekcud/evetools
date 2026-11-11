<?php

declare(strict_types=1);

namespace App\ApiResource\Input\Admin;

use Symfony\Component\Validator\Constraints as Assert;

class FailedMessageClassInput
{
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    public string $messageClass = '';
}
