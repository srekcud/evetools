<?php

declare(strict_types=1);

namespace App\Service\ESI;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class EveSsoTokenRevoker
{
    private const string EVE_REVOCATION_URL = 'https://login.eveonline.com/v2/oauth/revoke';
    private const int REQUEST_TIMEOUT = 15;
    private const int FIRST_SUCCESS_STATUS = 200;
    private const int LAST_SUCCESS_STATUS = 299;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly string $clientId,
        private readonly string $clientSecret,
    ) {
    }

    public function revoke(string $refreshToken, int $eveCharacterId): void
    {
        try {
            $statusCode = $this->httpClient->request('POST', self::EVE_REVOCATION_URL, [
                'auth_basic' => [$this->clientId, $this->clientSecret],
                'body' => [
                    'token_type_hint' => 'refresh_token',
                    'token' => $refreshToken,
                ],
                'timeout' => self::REQUEST_TIMEOUT,
            ])->getStatusCode();
        } catch (TransportExceptionInterface $exception) {
            $this->logger->warning('EVE SSO refresh token revocation failed for character {eveCharacterId}: {error}', [
                'eveCharacterId' => $eveCharacterId,
                'error' => $exception->getMessage(),
            ]);

            return;
        }

        if ($statusCode < self::FIRST_SUCCESS_STATUS || $statusCode > self::LAST_SUCCESS_STATUS) {
            $this->logger->warning('EVE SSO refresh token revocation answered {status} for character {eveCharacterId}', [
                'eveCharacterId' => $eveCharacterId,
                'status' => $statusCode,
            ]);
        }
    }

    public function reportUnreadableRefreshToken(int $eveCharacterId): void
    {
        $this->logger->warning('EVE SSO refresh token of character {eveCharacterId} could not be decrypted, it is not revoked', [
            'eveCharacterId' => $eveCharacterId,
        ]);
    }
}
