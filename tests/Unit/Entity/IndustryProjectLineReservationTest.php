<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectLineReservation;
use App\Entity\IndustryProjectParticipant;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(IndustryProjectLineReservation::class)]
#[Group('issue-1301')]
final class IndustryProjectLineReservationTest extends TestCase
{
    private const string RESERVED_AT = '2026-11-20 09:30:00';

    public function testReservationExpiresFortyEightHoursAfterItWasMade(): void
    {
        $reservedAt = new \DateTimeImmutable(self::RESERVED_AT);

        $reservation = $this->reservationMadeAt($reservedAt);

        self::assertEquals($reservedAt, $reservation->getReservedAt());
        self::assertEquals(new \DateTimeImmutable('2026-11-22 09:30:00'), $reservation->getExpiresAt());
    }

    public function testReservationMadeNowExpiresFortyEightHoursAfterItsReservedAt(): void
    {
        $reservation = new IndustryProjectLineReservation(new IndustryProjectBomLine(), new IndustryProjectParticipant(), 10);

        self::assertEquals($reservation->getReservedAt()->modify('+48 hours'), $reservation->getExpiresAt());
        self::assertSame(48, IndustryProjectLineReservation::LIFETIME_IN_HOURS);
    }

    public function testReservationIsActiveUntilItsExpiryThenExpired(): void
    {
        $reservation = $this->reservationMadeAt(new \DateTimeImmutable(self::RESERVED_AT));

        self::assertFalse($reservation->isExpiredAt(new \DateTimeImmutable('2026-11-22 09:29:59')));
        self::assertTrue($reservation->isExpiredAt(new \DateTimeImmutable('2026-11-22 09:30:00')));
        self::assertTrue($reservation->isExpiredAt(new \DateTimeImmutable('2026-11-22 09:30:01')));
    }

    public function testRenewingAnExpiredReservationRestartsTheFortyEightHours(): void
    {
        $reservation = $this->reservationMadeAt(new \DateTimeImmutable('2026-11-01 08:00:00'));
        $before = new \DateTimeImmutable();

        $reservation->renew();

        $after = new \DateTimeImmutable();
        self::assertGreaterThanOrEqual($before, $reservation->getReservedAt());
        self::assertLessThanOrEqual($after, $reservation->getReservedAt());
        self::assertEquals($reservation->getReservedAt()->modify('+48 hours'), $reservation->getExpiresAt());
        self::assertFalse($reservation->isExpiredAt($after));
    }

    private function reservationMadeAt(\DateTimeImmutable $reservedAt): IndustryProjectLineReservation
    {
        return new IndustryProjectLineReservation(new IndustryProjectBomLine(), new IndustryProjectParticipant(), 10, $reservedAt);
    }
}
