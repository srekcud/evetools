<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project;

use App\Entity\Character;
use App\Entity\IndustryProject;
use Doctrine\Common\Collections\ArrayCollection;
use App\Entity\IndustryProjectContribution;
use App\Entity\IndustryProjectParticipant;
use App\Entity\IndustryProjectSale;
use App\Entity\User;
use App\Enum\ContributionType;
use App\Repository\IndustryProjectContributionRepository;
use App\Repository\IndustryProjectSaleRepository;
use App\Service\Industry\Project\DistributionResult;
use App\Service\Industry\Project\ParticipantDistribution;
use App\Service\Industry\Project\ProjectDistributionService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(ProjectDistributionService::class)]
#[Group('issue-421')]
final class ProjectDistributionServiceCourierTest extends TestCase
{
    private const string OWNER_MEMBER_ID = '00000000-0000-0000-0000-000000421a01';
    private const string PARTNER_MEMBER_ID = '00000000-0000-0000-0000-000000421b02';

    private IndustryProjectContributionRepository&Stub $contributionRepository;
    private IndustryProjectSaleRepository&Stub $saleRepository;
    private ProjectDistributionService $service;

    protected function setUp(): void
    {
        $this->contributionRepository = $this->createStub(IndustryProjectContributionRepository::class);
        $this->saleRepository = $this->createStub(IndustryProjectSaleRepository::class);
        $this->service = new ProjectDistributionService($this->contributionRepository, $this->saleRepository);
    }

    public function testCourierRewardEntersTheProjectCostTheOwnersCostsAndTheMargin(): void
    {
        $owner = $this->member('Aria Solberg', self::OWNER_MEMBER_ID);
        $partner = $this->member('Brann Holt', self::PARTNER_MEMBER_ID);

        $result = $this->distributionOf(10_000.0, [
            $this->contribution($owner, ContributionType::Material, 3_000.0),
            $this->contribution($owner, ContributionType::Courier, 1_000.0),
            $this->contribution($partner, ContributionType::JobInstall, 1_000.0),
        ]);

        self::assertSame([5_000.0, 100.0], [$result->totalProjectCost, $result->marginPercent]);
        $byMemberId = $this->byMemberId($result);
        self::assertSame(
            [4_000.0, 3_000.0, 1_000.0, 80.0, 4_000.0, 8_000.0],
            [
                $byMemberId[self::OWNER_MEMBER_ID]->totalCostsEngaged,
                $byMemberId[self::OWNER_MEMBER_ID]->materialCosts,
                $byMemberId[self::OWNER_MEMBER_ID]->courierCosts,
                $byMemberId[self::OWNER_MEMBER_ID]->sharePercent,
                $byMemberId[self::OWNER_MEMBER_ID]->profitPart,
                $byMemberId[self::OWNER_MEMBER_ID]->payoutTotal,
            ],
        );
        self::assertSame([1_000.0, 0.0], [$byMemberId[self::PARTNER_MEMBER_ID]->totalCostsEngaged, $byMemberId[self::PARTNER_MEMBER_ID]->courierCosts]);
    }

    public function testWithoutCourierContributionTheCostIsUnchangedAndCourierCostsAreZero(): void
    {
        $owner = $this->member('Aria Solberg', self::OWNER_MEMBER_ID);

        $result = $this->distributionOf(10_000.0, [$this->contribution($owner, ContributionType::Material, 4_000.0)]);

        self::assertSame([4_000.0, 0.0], [$result->totalProjectCost, $this->byMemberId($result)[self::OWNER_MEMBER_ID]->courierCosts]);
    }

    public function testCourierContributionWithoutValueMakesTheCostAndTheMarginUnknownNeverPartial(): void
    {
        $owner = $this->member('Aria Solberg', self::OWNER_MEMBER_ID);

        $result = $this->distributionOf(10_000.0, [
            $this->contribution($owner, ContributionType::Material, 3_000.0),
            $this->contribution($owner, ContributionType::Courier, null),
        ]);

        $ownerDistribution = $this->byMemberId($result)[self::OWNER_MEMBER_ID];
        self::assertSame([null, null], [$result->totalProjectCost, $result->marginPercent]);
        self::assertSame([null, null, 3_000.0], [$ownerDistribution->totalCostsEngaged, $ownerDistribution->courierCosts, $ownerDistribution->materialCosts]);
    }

    private function distributionOf(float $revenue, array $approvedContributions): DistributionResult
    {
        $project = $this->createStub(IndustryProject::class);
        $project->method('getBrokerFeePercent')->willReturn(0.0);
        $project->method('getSalesTaxPercent')->willReturn(0.0);
        $project->method('getBomItems')->willReturn(new ArrayCollection());
        $this->saleRepository->method('findBy')->willReturn([(new IndustryProjectSale())->setTotalPrice($revenue)]);
        $this->contributionRepository->method('findApprovedByProject')->willReturn($approvedContributions);

        return $this->service->calculateDistribution($project);
    }

    /**
     * @return array<string, ParticipantDistribution>
     */
    private function byMemberId(DistributionResult $result): array
    {
        $byMemberId = [];
        foreach ($result->members as $member) {
            $byMemberId[$member->memberId] = $member;
        }

        return $byMemberId;
    }

    private function member(string $characterName, string $memberId): IndustryProjectParticipant
    {
        $character = $this->createStub(Character::class);
        $character->method('getName')->willReturn($characterName);
        $user = $this->createStub(User::class);
        $user->method('getMainCharacter')->willReturn($character);
        $member = (new IndustryProjectParticipant())->setUser($user);
        (new \ReflectionProperty(IndustryProjectParticipant::class, 'id'))->setValue($member, Uuid::fromString($memberId));

        return $member;
    }

    private function contribution(IndustryProjectParticipant $member, ContributionType $type, ?float $estimatedValue): IndustryProjectContribution
    {
        return (new IndustryProjectContribution())
            ->setMember($member)
            ->setType($type)
            ->setQuantity(1)
            ->setEstimatedValue($estimatedValue);
    }
}
