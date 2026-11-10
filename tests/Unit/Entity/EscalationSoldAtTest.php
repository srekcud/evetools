<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Escalation;
use App\Enum\EscalationSaleStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(Escalation::class)]
#[Group('issue-533')]
final class EscalationSoldAtTest extends TestCase
{
    public function testAnEscalationOnSaleHasNoSoldAt(): void
    {
        $escalation = new Escalation();

        $this->assertSame(EscalationSaleStatus::EnVente, $escalation->getSaleStatus());
        $this->assertNull($escalation->getSoldAt());
    }

    public function testMovingFromEnventeToVenduSetsSoldAtToNow(): void
    {
        $escalation = new Escalation();

        $before = new \DateTimeImmutable();
        $escalation->setSaleStatus(EscalationSaleStatus::Vendu);
        $after = new \DateTimeImmutable();

        $soldAt = $escalation->getSoldAt();
        $this->assertInstanceOf(\DateTimeImmutable::class, $soldAt);
        $this->assertGreaterThanOrEqual($before->getTimestamp(), $soldAt->getTimestamp());
        $this->assertLessThanOrEqual($after->getTimestamp(), $soldAt->getTimestamp());
    }

    public function testMovingFromVenduBackToEnventeClearsSoldAt(): void
    {
        $escalation = new Escalation();
        $escalation->setSaleStatus(EscalationSaleStatus::Vendu);
        $escalation->setSoldAt(new \DateTimeImmutable('2026-10-03 14:20:00'));

        $escalation->setSaleStatus(EscalationSaleStatus::EnVente);

        $this->assertNull($escalation->getSoldAt());
    }

    public function testSavingAVenduEscalationAgainKeepsTheOriginalSoldAt(): void
    {
        $soldAt = new \DateTimeImmutable('2026-10-03 14:20:00');
        $escalation = new Escalation();
        $escalation->setSaleStatus(EscalationSaleStatus::Vendu);
        $escalation->setSoldAt($soldAt);

        $escalation->setSaleStatus(EscalationSaleStatus::Vendu);

        $this->assertSame($soldAt, $escalation->getSoldAt());
    }

    public function testSavingAnEnventeEscalationAgainKeepsSoldAtNull(): void
    {
        $escalation = new Escalation();

        $escalation->setSaleStatus(EscalationSaleStatus::EnVente);

        $this->assertNull($escalation->getSoldAt());
    }
}
