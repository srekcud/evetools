<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\IndustryProjectCourierContract;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(IndustryProjectCourierContract::class)]
#[Group('issue-422')]
final class IndustryProjectCourierContractFailureNotificationTest extends TestCase
{
    public function testLinkingAnotherContractClearsTheNotifiedFailureAndReplacesTheContract(): void
    {
        $notifiedAt = new \DateTimeImmutable('2026-11-08T09:00:00+00:00');
        $linkedAt = new \DateTimeImmutable('2026-11-08T10:00:00+00:00');
        $link = (new IndustryProjectCourierContract())->setContractId(422_101)->setCharacterId(2_112_422_101);
        self::assertNull($link->getFailureNotifiedAt());
        $link->markFailureNotified($notifiedAt);
        self::assertSame($notifiedAt, $link->getFailureNotifiedAt());

        $link->link(422_102, 2_112_422_102, $linkedAt);

        self::assertNull($link->getFailureNotifiedAt());
        self::assertSame([422_102, 2_112_422_102, $linkedAt], [$link->getContractId(), $link->getCharacterId(), $link->getLinkedAt()]);
    }
}
