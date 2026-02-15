<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\PlanetaryPin;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PlanetaryPin::class)]
class PlanetaryPinTest extends TestCase
{
    // ===========================================
    // isExtractor Tests
    // ===========================================

    public function testIsExtractorReturnsTrueWhenProductTypeSet(): void
    {
        $pin = new PlanetaryPin();
        $pin->setExtractorProductTypeId(2073);

        $this->assertTrue($pin->isExtractor());
    }

    public function testIsExtractorReturnsFalseWhenNoProductType(): void
    {
        $pin = new PlanetaryPin();

        $this->assertFalse($pin->isExtractor());
    }

    // ===========================================
    // isFactory Tests
    // ===========================================

    public function testIsFactoryReturnsTrueWhenSchematicSetAndNotExtractor(): void
    {
        $pin = new PlanetaryPin();
        $pin->setSchematicId(121);

        $this->assertTrue($pin->isFactory());
    }

    public function testIsFactoryReturnsFalseWhenNoSchematic(): void
    {
        $pin = new PlanetaryPin();

        $this->assertFalse($pin->isFactory());
    }

    public function testIsFactoryReturnsFalseWhenAlsoExtractor(): void
    {
        // An extractor with a schematic is still an extractor, not a factory
        $pin = new PlanetaryPin();
        $pin->setSchematicId(121);
        $pin->setExtractorProductTypeId(2073);

        $this->assertFalse($pin->isFactory());
        $this->assertTrue($pin->isExtractor());
    }

    // ===========================================
    // isExpired Tests
    // ===========================================

    public function testIsExpiredReturnsTrueWhenExpiryInPast(): void
    {
        $pin = new PlanetaryPin();
        $pin->setExpiryTime(new \DateTimeImmutable('-1 hour'));

        $this->assertTrue($pin->isExpired());
    }

    public function testIsExpiredReturnsFalseWhenExpiryInFuture(): void
    {
        $pin = new PlanetaryPin();
        $pin->setExpiryTime(new \DateTimeImmutable('+1 hour'));

        $this->assertFalse($pin->isExpired());
    }

    public function testIsExpiredReturnsFalseWhenNoExpiry(): void
    {
        $pin = new PlanetaryPin();

        $this->assertFalse($pin->isExpired());
    }

    // ===========================================
    // isExpiringSoon Tests
    // ===========================================

    public function testIsExpiringSoonReturnsTrueWhenWithinThreshold(): void
    {
        // Expires in 12 hours, threshold is 24 hours
        $pin = new PlanetaryPin();
        $pin->setExpiryTime(new \DateTimeImmutable('+12 hours'));

        $this->assertTrue($pin->isExpiringSoon(24));
    }

    public function testIsExpiringSoonReturnsFalseWhenBeyondThreshold(): void
    {
        // Expires in 48 hours, threshold is 24 hours
        $pin = new PlanetaryPin();
        $pin->setExpiryTime(new \DateTimeImmutable('+48 hours'));

        $this->assertFalse($pin->isExpiringSoon(24));
    }

    public function testIsExpiringSoonReturnsFalseWhenAlreadyExpired(): void
    {
        $pin = new PlanetaryPin();
        $pin->setExpiryTime(new \DateTimeImmutable('-1 hour'));

        $this->assertFalse($pin->isExpiringSoon(24));
    }

    public function testIsExpiringSoonReturnsFalseWhenNoExpiry(): void
    {
        $pin = new PlanetaryPin();

        $this->assertFalse($pin->isExpiringSoon(24));
    }

    public function testIsExpiringSoonUsesDefaultThreshold(): void
    {
        // Default threshold is 24 hours. Expires in 12 hours.
        $pin = new PlanetaryPin();
        $pin->setExpiryTime(new \DateTimeImmutable('+12 hours'));

        $this->assertTrue($pin->isExpiringSoon());
    }

    public function testIsExpiringSoonWithCustomThreshold(): void
    {
        // Expires in 2 hours, threshold is 1 hour
        $pin = new PlanetaryPin();
        $pin->setExpiryTime(new \DateTimeImmutable('+2 hours'));

        $this->assertFalse($pin->isExpiringSoon(1));
    }

    // ===========================================
    // Setters / Getters
    // ===========================================

    public function testSetAndGetContents(): void
    {
        $pin = new PlanetaryPin();
        $contents = [
            ['type_id' => 2073, 'amount' => 5000],
            ['type_id' => 2389, 'amount' => 200],
        ];
        $pin->setContents($contents);

        $this->assertSame($contents, $pin->getContents());
    }

    public function testSetAndGetNullContents(): void
    {
        $pin = new PlanetaryPin();
        $pin->setContents(null);

        $this->assertNull($pin->getContents());
    }
}
