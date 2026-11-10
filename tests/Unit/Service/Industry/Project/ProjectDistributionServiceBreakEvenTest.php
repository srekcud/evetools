<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project;

use App\Entity\Character;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectContribution;
use App\Entity\IndustryProjectParticipant;
use App\Entity\User;
use App\Enum\ContributionType;
use App\Repository\IndustryProjectContributionRepository;
use App\Repository\IndustryProjectSaleRepository;
use App\Service\Industry\Project\ProjectDistributionService;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(ProjectDistributionService::class)]
final class ProjectDistributionServiceBreakEvenTest extends TestCase
{
    private function distributionOf(?float $contributionValue, int $fulfilled): \App\Service\Industry\Project\DistributionResult
    {
        $line = $this->createStub(IndustryProjectBomLine::class);
        $line->method('getTypeId')->willReturn(11993);
        $line->method('getTypeName')->willReturn('Hammerhead II');
        $line->method('getRequiredQuantity')->willReturn(100);
        $line->method('isFulfilled')->willReturn($fulfilled >= 100);
        $line->method('isJob')->willReturn(true);
        $line->method('getJobGroup')->willReturn('final');

        $project = $this->createStub(IndustryProject::class);
        $project->method('getBrokerFeePercent')->willReturn(1.38);
        $project->method('getSalesTaxPercent')->willReturn(2.62);
        $project->method('getBomItems')->willReturn(new ArrayCollection([$line]));

        $character = $this->createStub(Character::class);
        $character->method('getName')->willReturn('Brann Holt');
        $user = $this->createStub(User::class);
        $user->method('getMainCharacter')->willReturn($character);
        $member = $this->createStub(IndustryProjectParticipant::class);
        $member->method('getId')->willReturn(Uuid::v4());
        $member->method('getUser')->willReturn($user);

        $contribution = $this->createStub(IndustryProjectContribution::class);
        $contribution->method('getMember')->willReturn($member);
        $contribution->method('getType')->willReturn(ContributionType::Material);
        $contribution->method('getEstimatedValue')->willReturn($contributionValue);

        $contributions = $this->createStub(IndustryProjectContributionRepository::class);
        $contributions->method('findApprovedByProject')->willReturn($contributionValue === null ? [] : [$contribution]);
        $sales = $this->createStub(IndustryProjectSaleRepository::class);
        $sales->method('findBy')->willReturn([]);

        return (new ProjectDistributionService($contributions, $sales))->calculateDistribution($project);
    }

    public function testTheDistributionCarriesTheBreakEvenOfTheApprovedCostsAlreadySpent(): void
    {
        $distribution = $this->distributionOf(9_280_000.0, 100);

        self::assertEqualsWithDelta(96_666.67, $distribution->breakEvenPerUnit, 0.01);
        self::assertNull($distribution->breakEvenUnknownReason);
    }

    public function testTheDistributionExplainsAnUnknownBreakEvenWhenTheFinalJobsAreNotDelivered(): void
    {
        $distribution = $this->distributionOf(9_280_000.0, 40);

        self::assertNull($distribution->breakEvenPerUnit);
        self::assertSame('jobs_not_delivered', $distribution->breakEvenUnknownReason);
    }

    public function testTheDistributionKeepsItsOtherFiguresWhenTheBreakEvenIsKnown(): void
    {
        $distribution = $this->distributionOf(9_280_000.0, 100);

        self::assertSame(9_280_000.0, $distribution->totalProjectCost);
        self::assertTrue($distribution->awaitingSales);
    }
}
