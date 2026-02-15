<?php

declare(strict_types=1);

namespace App\Service\ESI;

use App\Dto\EveTokenDto;
use App\Entity\EveToken;
use App\Exception\EsiApiException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class TokenManager
{
    private const EVE_TOKEN_URL = 'https://login.eveonline.com/v2/oauth/token';
    private const REQUEST_TIMEOUT = 15;

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
        } catch (TransportExceptionInterface $e) {
            throw EsiApiException::unauthorized('Network error while refreshing token: ' . $e->getMessage());
        } catch (\Throwable $e) {
            throw EsiApiException::unauthorized('Failed to refresh access token: ' . $e->getMessage());
        }
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
