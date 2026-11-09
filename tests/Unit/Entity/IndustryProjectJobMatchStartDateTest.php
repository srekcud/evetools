<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\CachedIndustryJob;
use App\Entity\IndustryProjectJobMatch;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(IndustryProjectJobMatch::class)]
#[Group('issue-321')]
final class IndustryProjectJobMatchStartDateTest extends TestCase
{
    public function testCopyingTheStateOfAnEsiJobCopiesItsStartDateAlongTheOtherFields(): void
    {
        $job = (new CachedIndustryJob())
            ->setStatus('active')
            ->setStartDate(new \DateTimeImmutable('2026-11-09T18:30:00+00:00'))
            ->setEndDate(new \DateTimeImmutable('2026-11-10T02:45:00+00:00'))
            ->setCost(1_482_350.25)
            ->setRuns(7);
        $match = new IndustryProjectJobMatch();

        $match->copyJobState($job);

        self::assertSame('2026-11-09T18:30:00+00:00', $match->getStartDate()?->format(\DateTimeInterface::ATOM));
        self::assertSame(['active', '2026-11-10T02:45:00+00:00', 1_482_350.25, 7], [
            $match->getStatus(),
            $match->getEndDate()->format(\DateTimeInterface::ATOM),
            $match->getCost(),
            $match->getRuns(),
        ]);
    }

    public function testALinkWithoutStartDateKeepsItUnknownInsteadOfTheTimeItWasMatched(): void
    {
        $match = new IndustryProjectJobMatch();

        self::assertNull($match->getStartDate());
    }
}
