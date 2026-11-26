<?php

declare(strict_types=1);

namespace App\ApiResource\Input\Notification;

use Symfony\Component\Validator\Constraints as Assert;

class DiscordWebhookInput
{
    #[Assert\NotBlank]
    public string $url = '';
}
