<?php

declare(strict_types=1);

namespace App\Service\Notification;

class DiscordWebhookUrlCipher
{
    private const string KEY_CONTEXT = 'discord-webhook-url';

    public function __construct(private readonly string $encryptionKey)
    {
    }

    public function encrypt(string $url): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return base64_encode($nonce . sodium_crypto_secretbox($url, $nonce, $this->deriveKey()));
    }

    public function decrypt(string $encrypted): string
    {
        $key = $this->deriveKey();
        $decoded = base64_decode($encrypted, true);

        if ($decoded === false || strlen($decoded) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
            throw new \RuntimeException('Failed to decode the encrypted Discord webhook URL');
        }

        $nonce = substr($decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = substr($decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, $key);

        if ($plaintext === false) {
            throw new \RuntimeException('Failed to decrypt the Discord webhook URL');
        }

        return $plaintext;
    }

    private function deriveKey(): string
    {
        $baseKey = base64_decode($this->encryptionKey, true);

        if ($baseKey === false || strlen($baseKey) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new \RuntimeException('Invalid encryption key');
        }

        return sodium_crypto_generichash(self::KEY_CONTEXT, $baseKey, SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }
}
