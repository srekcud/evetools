<?php

declare(strict_types=1);

namespace App\Service\ESI;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class EveSsoTokenRevoker
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly string $clientId,
        private readonly string $clientSecret,
    ) {
    }

    public function revoke(string $refreshToken, int $eveCharacterId): void
    {
    }
}
