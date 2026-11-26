<?php

declare(strict_types=1);

namespace App\Service\Notification;

use App\Entity\User;
use App\Repository\UserDiscordWebhookRepository;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class DiscordWebhookSender
{
    private const int MAX_CONTENT_LENGTH = 2000;
    private const int REQUEST_TIMEOUT_SECONDS = 5;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly UserDiscordWebhookRepository $repository,
        private readonly DiscordWebhookUrlCipher $cipher,
    ) {
    }

    public function send(User $user, string $title, string $message): void
    {
        $webhook = $this->repository->findByUser($user);
        if ($webhook === null) {
            return;
        }

        try {
            $url = (new DiscordWebhookUrlValidator())->validate($this->cipher->decrypt($webhook->getUrlEncrypted()));
        } catch (\InvalidArgumentException) {
            throw new \RuntimeException('Stored Discord webhook URL is no longer valid');
        }

        try {
            $statusCode = $this->httpClient->request('POST', $url, [
                'json' => [
                    'content' => mb_substr(sprintf("**%s**\n%s", $title, $message), 0, self::MAX_CONTENT_LENGTH),
                    'allowed_mentions' => ['parse' => []],
                ],
                'max_redirects' => 0,
                'timeout' => self::REQUEST_TIMEOUT_SECONDS,
                'max_duration' => self::REQUEST_TIMEOUT_SECONDS,
            ])->getStatusCode();
        } catch (TransportExceptionInterface $exception) {
            throw new \RuntimeException('Discord webhook request failed: ' . $exception::class);
        }

        if ($statusCode < 200 || $statusCode >= 300) {
            throw new \RuntimeException(sprintf('Discord webhook answered with HTTP %d', $statusCode));
        }
    }
}
