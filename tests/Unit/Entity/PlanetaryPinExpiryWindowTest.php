<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\PlanetaryPin;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(PlanetaryPin::class)]
#[Group('issue-537')]
final class PlanetaryPinExpiryWindowTest extends TestCase
{
    public function testExtractorExpiringJustInsideTheWindowIsExpiring(): void
    {
        $pin = $this->pinExpiringIn('+59 minutes 30 seconds');

        $this->assertTrue($pin->isExpiringWithinMinutes(60));
    }

    public function testExtractorExpiringJustOutsideTheWindowIsNotExpiring(): void
    {
        $pin = $this->pinExpiringIn('+60 minutes 30 seconds');

        $this->assertFalse($pin->isExpiringWithinMinutes(60));
    }

    public function testWindowIsMeasuredInMinutesNotInHours(): void
    {
        $pin = $this->pinExpiringIn('+90 minutes');

        $this->assertFalse($pin->isExpiringWithinMinutes(60));
        $this->assertTrue($pin->isExpiringWithinMinutes(120));
    }

    public function testExtractorAlreadyExpiredIsNotExpiringWhateverTheWindow(): void
    {
        $pin = $this->pinExpiringIn('-1 minute');

        $this->assertFalse($pin->isExpiringWithinMinutes(60));
        $this->assertFalse($pin->isExpiringWithinMinutes(10080));
    }

    public function testPinWithoutExpiryTimeIsNeverExpiring(): void
    {
        $pin = new PlanetaryPin();

        $this->assertFalse($pin->isExpiringWithinMinutes(10080));
    }

    public function testZeroMinuteWindowNeverContainsAFutureExpiry(): void
    {
        $pin = $this->pinExpiringIn('+1 minute');

        $this->assertFalse($pin->isExpiringWithinMinutes(0));
    }

    public function testExpiringSoonFollowsTheWindowInHoursThroughMinutes(): void
    {
        $this->assertTrue($this->pinExpiringIn('+2 hours')->isExpiringSoon(3));
        $this->assertFalse($this->pinExpiringIn('+4 hours')->isExpiringSoon(3));
        $this->assertTrue($this->pinExpiringIn('+23 hours')->isExpiringSoon());
        $this->assertFalse($this->pinExpiringIn('+25 hours')->isExpiringSoon());
    }

    private function pinExpiringIn(string $modifier): PlanetaryPin
    {
        return (new PlanetaryPin())->setExpiryTime(new \DateTimeImmutable($modifier));
    }
}
