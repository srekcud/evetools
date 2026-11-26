<?php

declare(strict_types=1);

namespace App\Service\Notification;

use App\Entity\User;
use App\Repository\UserDiscordWebhookRepository;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class DiscordWebhookSender
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly UserDiscordWebhookRepository $repository,
        private readonly DiscordWebhookUrlCipher $cipher,
    ) {
    }

    public function send(User $user, string $title, string $message): void
    {
    }
}
