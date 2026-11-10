<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project;

use App\Service\Industry\Project\DistributionResult;
use App\Service\Industry\Project\ParticipantDistribution;
use App\Service\Industry\Project\ViewerEngagedCapital;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(ViewerEngagedCapital::class)]
#[Group('issue-569')]
final class ViewerEngagedCapitalTest extends TestCase
{
    private const string VIEWER_MEMBER_ID = '0192a4e0-0000-7000-8000-000000000001';
    private const string OTHER_MEMBER_ID = '0192a4e0-0000-7000-8000-000000000002';
    private const string ABSENT_MEMBER_ID = '0192a4e0-0000-7000-8000-000000000003';
    private const float VIEWER_COST = 1_750_000.0;
    private const float OTHER_COST = 3_000_000.0;

    public function testCapitalIsTheCostEngagedByTheViewerAlone(): void
    {
        $distribution = $this->distributionOf(
            $this->participant(self::OTHER_MEMBER_ID, self::OTHER_COST),
            $this->participant(self::VIEWER_MEMBER_ID, self::VIEWER_COST),
        );

        self::assertSame(self::VIEWER_COST, ViewerEngagedCapital::of($distribution, self::VIEWER_MEMBER_ID));
    }

    public function testCapitalIsZeroWhenTheViewerHasNoApprovedContribution(): void
    {
        $distribution = $this->distributionOf($this->participant(self::OTHER_MEMBER_ID, self::OTHER_COST));

        self::assertSame(0.0, ViewerEngagedCapital::of($distribution, self::ABSENT_MEMBER_ID));
    }

    public function testCapitalIsUnknownWhenAContributionOfTheViewerHasNoEstimatedValue(): void
    {
        $distribution = $this->distributionOf($this->participant(self::VIEWER_MEMBER_ID, null));

        self::assertNull(ViewerEngagedCapital::of($distribution, self::VIEWER_MEMBER_ID));
    }

    public function testCapitalIsZeroForAProjectWithoutAnyContribution(): void
    {
        self::assertSame(0.0, ViewerEngagedCapital::of($this->distributionOf(), self::VIEWER_MEMBER_ID));
    }

    private function participant(string $memberId, ?float $totalCostsEngaged): ParticipantDistribution
    {
        return new ParticipantDistribution(
            memberId: $memberId,
            characterName: 'Pilot',
            totalCostsEngaged: $totalCostsEngaged,
            materialCosts: $totalCostsEngaged,
            jobInstallCosts: 0.0,
            bpcCosts: 0.0,
            lineRentalCosts: 0.0,
            courierCosts: 0.0,
            sharePercent: null,
            profitPart: null,
            payoutTotal: null,
        );
    }

    private function distributionOf(ParticipantDistribution ...$members): DistributionResult
    {
        return new DistributionResult(
            totalRevenue: 0.0,
            brokerFee: null,
            salesTax: null,
            netRevenue: null,
            totalProjectCost: null,
            marginPercent: null,
            members: array_values($members),
            awaitingSales: true,
        );
    }
}
