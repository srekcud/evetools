<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Notification;

use App\Service\Notification\DiscordWebhookUrlCipher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(DiscordWebhookUrlCipher::class)]
#[Group('issue-1392')]
final class DiscordWebhookUrlCipherTest extends TestCase
{
    private const string WEBHOOK_URL = 'https://discord.com/api/webhooks/123456789012345678/abcDEF_ghi-JKL0123456789xyz';

    private DiscordWebhookUrlCipher $cipher;

    protected function setUp(): void
    {
        $this->cipher = new DiscordWebhookUrlCipher(base64_encode(str_repeat('k', SODIUM_CRYPTO_SECRETBOX_KEYBYTES)));
    }

    public function testDecryptReturnsTheEncryptedUrl(): void
    {
        self::assertSame(self::WEBHOOK_URL, $this->cipher->decrypt($this->cipher->encrypt(self::WEBHOOK_URL)));
    }

    public function testEncryptedValueIsBase64AndHidesTheUrl(): void
    {
        $encrypted = $this->cipher->encrypt(self::WEBHOOK_URL);

        self::assertNotSame(self::WEBHOOK_URL, $encrypted);
        self::assertStringNotContainsString('abcDEF_ghi-JKL0123456789xyz', $encrypted);
        self::assertStringNotContainsString('discord.com', $encrypted);
        self::assertNotFalse(base64_decode($encrypted, true));
    }

    public function testEncryptingTheSameUrlTwiceGivesDifferentValues(): void
    {
        self::assertNotSame($this->cipher->encrypt(self::WEBHOOK_URL), $this->cipher->encrypt(self::WEBHOOK_URL));
    }

    public function testDecryptThrowsOnTamperedCiphertext(): void
    {
        $encrypted = $this->cipher->encrypt(self::WEBHOOK_URL);
        self::assertNotSame('', $encrypted);
        $raw = (string) base64_decode($encrypted, true);
        $lastIndex = strlen($raw) - 1;
        $raw[$lastIndex] = chr(ord($raw[$lastIndex]) ^ 1);

        $this->expectException(\RuntimeException::class);

        $this->cipher->decrypt(base64_encode($raw));
    }

    public function testDecryptThrowsOnValueThatIsNotBase64(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->cipher->decrypt('not base64 !!');
    }

    public function testDecryptThrowsOnEmptyValue(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->cipher->decrypt('');
    }

    public function testDecryptThrowsWithAnotherKey(): void
    {
        $encrypted = $this->cipher->encrypt(self::WEBHOOK_URL);
        $otherCipher = new DiscordWebhookUrlCipher(base64_encode(str_repeat('z', SODIUM_CRYPTO_SECRETBOX_KEYBYTES)));

        $this->expectException(\RuntimeException::class);

        $otherCipher->decrypt($encrypted);
    }

    public function testEncryptThrowsWhenTheKeyIsNotAValidBase64Key(): void
    {
        $this->expectException(\RuntimeException::class);

        (new DiscordWebhookUrlCipher('too short'))->encrypt(self::WEBHOOK_URL);
    }
}
