<?php

declare(strict_types=1);

namespace App\Service\Notification;

class DiscordWebhookUrlCipher
{
    public function __construct(private readonly string $encryptionKey)
    {
    }

    public function encrypt(string $url): string
    {
        return '';
    }

    public function decrypt(string $encrypted): string
    {
        return '';
    }
}
