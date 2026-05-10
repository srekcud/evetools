<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Stateless HMAC-signed OAuth state generator/verifier.
 *
 * Format: {nonce_hex}.{timestamp}.{signature_hex}
 *   - nonce: 16 random bytes hex-encoded (32 chars)
 *   - timestamp: unix time (int)
 *   - signature: hash_hmac('sha256', "{nonce}.{timestamp}", secret) hex-encoded (64 chars)
 *
 * No persistence required: signature ensures authenticity, timestamp ensures freshness.
 */
final class OAuthStateSigner
{
    public function __construct(
        private readonly string $secret,
        private readonly int $ttl = 600,
    ) {
    }

    public function generate(): string
    {
        $nonce = bin2hex(random_bytes(16));
        $timestamp = time();
        $signature = $this->sign($nonce, $timestamp);

        return $nonce . '.' . $timestamp . '.' . $signature;
    }

    public function verify(string $state): bool
    {
        if ($state === '') {
            return false;
        }

        $parts = explode('.', $state);
        if (count($parts) !== 3) {
            return false;
        }

        [$nonce, $timestampStr, $signature] = $parts;

        if (!ctype_xdigit($nonce) || strlen($nonce) !== 32) {
            return false;
        }

        if (!ctype_digit($timestampStr)) {
            return false;
        }

        if (!ctype_xdigit($signature) || strlen($signature) !== 64) {
            return false;
        }

        $timestamp = (int) $timestampStr;
        $age = time() - $timestamp;
        if ($age < 0 || $age > $this->ttl) {
            return false;
        }

        $expected = $this->sign($nonce, $timestamp);

        return hash_equals($expected, $signature);
    }

    private function sign(string $nonce, int $timestamp): string
    {
        return hash_hmac('sha256', $nonce . '.' . $timestamp, $this->secret);
    }
}
