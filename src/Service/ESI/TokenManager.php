<?php

declare(strict_types=1);

namespace App\Service\ESI;

use App\Dto\EveTokenDto;
use App\Entity\EveToken;
use App\Exception\EsiApiException;
use App\Exception\EveAuthRequiredException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class TokenManager
{
    private const EVE_TOKEN_URL = 'https://login.eveonline.com/v2/oauth/token';
    private const REQUEST_TIMEOUT = 15;
    private const HTTP_BAD_REQUEST = 400;
    /** Same convention as EsiClient: no HTTP response at all */
    private const NO_HTTP_RESPONSE = 0;

    public function __construct(
        private readonly string $encryptionKey,
        private readonly HttpClientInterface $httpClient,
        private readonly EntityManagerInterface $entityManager,
        private readonly string $clientId,
        private readonly string $clientSecret,
    ) {
    }

    public function encryptRefreshToken(string $token): string
    {
        $key = $this->getKey();
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = sodium_crypto_secretbox($token, $nonce, $key);

        return base64_encode($nonce . $ciphertext);
    }

    public function decryptRefreshToken(string $encrypted): string
    {
        $key = $this->getKey();
        $decoded = base64_decode($encrypted, true);

        if ($decoded === false) {
            throw new \RuntimeException('Failed to decode encrypted token');
        }

        $nonce = substr($decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = substr($decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        $plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, $key);

        if ($plaintext === false) {
            throw new \RuntimeException('Failed to decrypt token');
        }

        return $plaintext;
    }

    public function refreshAccessToken(EveToken $token): EveToken
    {
        $refreshToken = $this->decryptRefreshToken($token->getRefreshTokenEncrypted());

        try {
            $response = $this->httpClient->request('POST', self::EVE_TOKEN_URL, [
                'headers' => [
                    'Content-Type' => 'application/x-www-form-urlencoded',
                    'Authorization' => 'Basic ' . base64_encode($this->clientId . ':' . $this->clientSecret),
                ],
                'body' => [
                    'grant_type' => 'refresh_token',
                    'refresh_token' => $refreshToken,
                ],
                'timeout' => self::REQUEST_TIMEOUT,
            ]);

            $data = $response->toArray();
        } catch (HttpExceptionInterface $e) {
            $statusCode = $e->getResponse()->getStatusCode();
            if ($statusCode === self::HTTP_BAD_REQUEST && $this->isInvalidGrant($e->getResponse())) {
                throw $this->invalidateAuthOf($token);
            }

            throw new EsiApiException('EVE SSO rejected the token refresh: ' . $e->getMessage(), $statusCode, previous: $e);
        } catch (TransportExceptionInterface $e) {
            throw new EsiApiException('Network error while refreshing token: ' . $e->getMessage(), self::NO_HTTP_RESPONSE, previous: $e);
        }

        $token->setAccessToken($data['access_token']);
        $token->setAccessTokenExpiresAt(new \DateTimeImmutable("+{$data['expires_in']} seconds"));

        if (isset($data['refresh_token'])) {
            $token->setRefreshTokenEncrypted($this->encryptRefreshToken($data['refresh_token']));
        }

        // Update scopes from response or JWT
        $scopes = array_filter(explode(' ', $data['scope'] ?? ''), fn($s) => $s !== '');
        if (empty($scopes)) {
            $scopes = $this->extractScopesFromJwt($data['access_token']);
        }
        if (!empty($scopes)) {
            $token->setScopes($scopes);
        }

        $this->entityManager->flush();

        return $token;
    }

    /** EVE SSO answers `invalid_grant` when the player revoked the application or the refresh token expired */
    private function isInvalidGrant(ResponseInterface $response): bool
    {
        $body = json_decode($response->getContent(false), true);

        return is_array($body) && ($body['error'] ?? null) === 'invalid_grant';
    }

    private function invalidateAuthOf(EveToken $token): EveAuthRequiredException
    {
        $character = $token->getCharacter()
            ?? throw new \LogicException('Revoked EVE token is not attached to any character');

        $character->getUser()?->markAuthInvalid();
        $this->entityManager->flush();

        return new EveAuthRequiredException((string) $character->getEveCharacterId());
    }

    /**
     * Extract scopes from EVE JWT access token payload
     */
    /** @return list<string> */
    public function extractScopesFromJwt(string $jwt): array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            return [];
        }

        $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
        if (!is_array($payload)) {
            return [];
        }

        // EVE JWT has scopes as space-separated string or array in 'scp' claim
        $scp = $payload['scp'] ?? [];
        if (is_string($scp)) {
            return array_values(array_filter(explode(' ', $scp), fn($s) => $s !== ''));
        }
        if (is_array($scp)) {
            return array_values(array_filter($scp, fn($s) => is_string($s) && $s !== ''));
        }

        return [];
    }

    public function isAccessTokenExpired(EveToken $token): bool
    {
        return $token->isExpired();
    }

    public function isAccessTokenExpiringSoon(EveToken $token, int $seconds = 300): bool
    {
        return $token->isExpiringSoon($seconds);
    }

    public function getValidAccessToken(EveToken $token): string
    {
        if ($this->isAccessTokenExpiringSoon($token)) {
            $token = $this->refreshAccessToken($token);
        }

        return $token->getAccessToken();
    }

    public function createTokenFromDto(EveTokenDto $dto): EveToken
    {
        $token = new EveToken();
        $token->setAccessToken($dto->accessToken);
        $token->setRefreshTokenEncrypted($this->encryptRefreshToken($dto->refreshToken));
        $token->setAccessTokenExpiresAt($dto->getExpiresAt());
        $token->setScopes($dto->scopes);

        return $token;
    }

    private function getKey(): string
    {
        $decoded = base64_decode($this->encryptionKey, true);

        if ($decoded === false || strlen($decoded) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new \RuntimeException('Invalid encryption key');
        }

        return $decoded;
    }
}
