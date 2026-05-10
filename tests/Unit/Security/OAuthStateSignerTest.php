<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Security\OAuthStateSigner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(OAuthStateSigner::class)]
class OAuthStateSignerTest extends TestCase
{
    private const SECRET = 'test-secret-do-not-use-in-prod';
    private const TTL = 600;

    private OAuthStateSigner $signer;

    protected function setUp(): void
    {
        $this->signer = new OAuthStateSigner(self::SECRET, self::TTL);
    }

    public function testGenerateReturnsThreeDotSeparatedSegmentsWithValidFormats(): void
    {
        $state = $this->signer->generate();

        $parts = explode('.', $state);
        $this->assertCount(3, $parts);

        [$nonce, $timestamp, $signature] = $parts;

        $this->assertSame(32, strlen($nonce));
        $this->assertTrue(ctype_xdigit($nonce));
        $this->assertTrue(ctype_digit($timestamp));
        $this->assertSame(64, strlen($signature));
        $this->assertTrue(ctype_xdigit($signature));
    }

    public function testRoundTripVerifyReturnsTrue(): void
    {
        $state = $this->signer->generate();

        $this->assertTrue($this->signer->verify($state));
    }

    public function testGenerateProducesUniqueNonces(): void
    {
        $a = $this->signer->generate();
        $b = $this->signer->generate();

        $this->assertNotSame($a, $b);
    }

    public function testStateWithBadSignatureIsRejected(): void
    {
        $state = $this->signer->generate();
        $parts = explode('.', $state);
        // Flip last hex char of signature to corrupt it (preserving hex format)
        $sig = $parts[2];
        $lastChar = $sig[63];
        $newChar = $lastChar === '0' ? '1' : '0';
        $parts[2] = substr($sig, 0, 63) . $newChar;
        $tampered = implode('.', $parts);

        $this->assertFalse($this->signer->verify($tampered));
    }

    public function testExpiredStateIsRejected(): void
    {
        $nonce = bin2hex(random_bytes(16));
        $oldTimestamp = time() - self::TTL - 1;
        $signature = hash_hmac('sha256', $nonce . '.' . $oldTimestamp, self::SECRET);
        $state = $nonce . '.' . $oldTimestamp . '.' . $signature;

        $this->assertFalse($this->signer->verify($state));
    }

    public function testStateAtBoundaryIsAccepted(): void
    {
        $nonce = bin2hex(random_bytes(16));
        $timestamp = time() - self::TTL;
        $signature = hash_hmac('sha256', $nonce . '.' . $timestamp, self::SECRET);
        $state = $nonce . '.' . $timestamp . '.' . $signature;

        $this->assertTrue($this->signer->verify($state));
    }

    public function testFutureTimestampIsRejected(): void
    {
        $nonce = bin2hex(random_bytes(16));
        $futureTimestamp = time() + 60;
        $signature = hash_hmac('sha256', $nonce . '.' . $futureTimestamp, self::SECRET);
        $state = $nonce . '.' . $futureTimestamp . '.' . $signature;

        $this->assertFalse($this->signer->verify($state));
    }

    public function testEmptyStringIsRejected(): void
    {
        $this->assertFalse($this->signer->verify(''));
    }

    public function testSingleSegmentIsRejected(): void
    {
        $this->assertFalse($this->signer->verify('abc123'));
    }

    public function testTwoSegmentsAreRejected(): void
    {
        $this->assertFalse($this->signer->verify('abc.123'));
    }

    public function testFourSegmentsAreRejected(): void
    {
        $state = $this->signer->generate();
        $this->assertFalse($this->signer->verify($state . '.extra'));
    }

    public function testNonHexNonceIsRejected(): void
    {
        $nonce = str_repeat('z', 32);
        $timestamp = time();
        $signature = hash_hmac('sha256', $nonce . '.' . $timestamp, self::SECRET);
        $state = $nonce . '.' . $timestamp . '.' . $signature;

        $this->assertFalse($this->signer->verify($state));
    }

    public function testNonceWithWrongLengthIsRejected(): void
    {
        $nonce = bin2hex(random_bytes(8)); // 16 chars instead of 32
        $timestamp = time();
        $signature = hash_hmac('sha256', $nonce . '.' . $timestamp, self::SECRET);
        $state = $nonce . '.' . $timestamp . '.' . $signature;

        $this->assertFalse($this->signer->verify($state));
    }

    public function testNonNumericTimestampIsRejected(): void
    {
        $nonce = bin2hex(random_bytes(16));
        $timestamp = 'notanumber';
        $signature = hash_hmac('sha256', $nonce . '.' . $timestamp, self::SECRET);
        $state = $nonce . '.' . $timestamp . '.' . $signature;

        $this->assertFalse($this->signer->verify($state));
    }

    public function testNonHexSignatureIsRejected(): void
    {
        $nonce = bin2hex(random_bytes(16));
        $timestamp = time();
        $signature = str_repeat('z', 64);
        $state = $nonce . '.' . $timestamp . '.' . $signature;

        $this->assertFalse($this->signer->verify($state));
    }

    public function testStateSignedWithDifferentSecretIsRejected(): void
    {
        $other = new OAuthStateSigner('different-secret', self::TTL);
        $foreignState = $other->generate();

        $this->assertFalse($this->signer->verify($foreignState));
    }
}
