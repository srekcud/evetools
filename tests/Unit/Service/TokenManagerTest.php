<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\EveToken;
use App\Service\ESI\TokenManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[CoversClass(TokenManager::class)]
class TokenManagerTest extends TestCase
{
    private TokenManager $tokenManager;
    private string $encryptionKey;

    protected function setUp(): void
    {
        $this->encryptionKey = base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));

        $this->tokenManager = new TokenManager(
            $this->encryptionKey,
            $this->createStub(HttpClientInterface::class),
            $this->createStub(EntityManagerInterface::class),
            'test_client_id',
            'test_client_secret',
        );
    }

    public function testEncryptDecryptRefreshToken(): void
    {
        $original = 'refresh_token_value_12345';

        $encrypted = $this->tokenManager->encryptRefreshToken($original);
        $decrypted = $this->tokenManager->decryptRefreshToken($encrypted);

        $this->assertSame($original, $decrypted);
        $this->assertNotSame($original, $encrypted);
    }

    public function testEncryptedTokenIsDifferentEachTime(): void
    {
        $original = 'refresh_token_value';

        $encrypted1 = $this->tokenManager->encryptRefreshToken($original);
        $encrypted2 = $this->tokenManager->encryptRefreshToken($original);

        // Due to random nonce, encryptions should differ
        $this->assertNotSame($encrypted1, $encrypted2);

        // But both should decrypt to the same value
        $this->assertSame($original, $this->tokenManager->decryptRefreshToken($encrypted1));
        $this->assertSame($original, $this->tokenManager->decryptRefreshToken($encrypted2));
    }

    public function testIsAccessTokenExpired(): void
    {
        $expiredToken = new EveToken();
        $expiredToken->setAccessTokenExpiresAt(new \DateTimeImmutable('-1 hour'));

        $this->assertTrue($this->tokenManager->isAccessTokenExpired($expiredToken));

        $validToken = new EveToken();
        $validToken->setAccessTokenExpiresAt(new \DateTimeImmutable('+1 hour'));

        $this->assertFalse($this->tokenManager->isAccessTokenExpired($validToken));
    }

    public function testIsAccessTokenExpiringSoon(): void
    {
        // Token expiring in 2 minutes (less than default 5 minutes threshold)
        $expiringSoonToken = new EveToken();
        $expiringSoonToken->setAccessTokenExpiresAt(new \DateTimeImmutable('+2 minutes'));

        $this->assertTrue($this->tokenManager->isAccessTokenExpiringSoon($expiringSoonToken));

        // Token expiring in 10 minutes (more than threshold)
        $notExpiringSoonToken = new EveToken();
        $notExpiringSoonToken->setAccessTokenExpiresAt(new \DateTimeImmutable('+10 minutes'));

        $this->assertFalse($this->tokenManager->isAccessTokenExpiringSoon($notExpiringSoonToken));
    }

    public function testDecryptWithInvalidDataThrowsException(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->tokenManager->decryptRefreshToken('invalid_base64_!@#$');
    }
}
