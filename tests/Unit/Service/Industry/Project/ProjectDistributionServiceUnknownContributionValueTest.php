<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project;

use App\Entity\Character;
use App\Entity\IndustryProject;
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
#[Group('issue-363')]
final class ProjectDistributionServiceUnknownContributionValueTest extends TestCase
{
    private const string URSULA_MEMBER_ID = '00000000-0000-0000-0000-000000363a01';
    private const string VALDIS_MEMBER_ID = '00000000-0000-0000-0000-000000363b02';
    private const string WENZEL_MEMBER_ID = '00000000-0000-0000-0000-000000363c03';

    private IndustryProjectContributionRepository&Stub $contributionRepository;
    private IndustryProjectSaleRepository&Stub $saleRepository;
    private ProjectDistributionService $service;

    protected function setUp(): void
    {
        $this->contributionRepository = $this->createStub(IndustryProjectContributionRepository::class);
        $this->saleRepository = $this->createStub(IndustryProjectSaleRepository::class);
        $this->service = new ProjectDistributionService($this->contributionRepository, $this->saleRepository);
    }

    public function testProductionCostIsTheSumOfTheApprovedValuesAndPayoutsFollowTheMargin(): void
    {
        $ursula = $this->member('Ursula Kane', self::URSULA_MEMBER_ID);
        $valdis = $this->member('Valdis Orn', self::VALDIS_MEMBER_ID);

        $result = $this->distributionOf([
            $this->contribution($ursula, ContributionType::Material, 3_000.0),
            $this->contribution($valdis, ContributionType::JobInstall, 1_000.0),
        ]);

        self::assertSame([5_000.0, 100.0, 150.0, 4_750.0, 4_000.0, 18.75], $this->totalsOf($result));
        self::assertSame(
            [[3_000.0, 75.0, 562.5, 3_562.5], [1_000.0, 25.0, 187.5, 1_187.5]],
            array_map(
                static fn (ParticipantDistribution $member): array => [$member->totalCostsEngaged, $member->sharePercent, $member->profitPart, $member->payoutTotal],
                $result->members,
            ),
        );
    }

    public function testOneApprovedContributionWithoutValueMakesTheCostMarginSharesProfitPartsAndPayoutsUnknownNeverPartial(): void
    {
        $ursula = $this->member('Ursula Kane', self::URSULA_MEMBER_ID);
        $valdis = $this->member('Valdis Orn', self::VALDIS_MEMBER_ID);
        $wenzel = $this->member('Wenzel Roth', self::WENZEL_MEMBER_ID);

        $result = $this->distributionOf([
            $this->contribution($ursula, ContributionType::Material, 3_000.0),
            $this->contribution($valdis, ContributionType::JobInstall, 1_000.0),
            $this->contribution($wenzel, ContributionType::JobInstall, null),
        ]);

        self::assertSame([5_000.0, 100.0, 150.0, 4_750.0, null, null], $this->totalsOf($result));
        self::assertSame(
            [[null, null, null], [null, null, null], [null, null, null]],
            array_map(
                static fn (ParticipantDistribution $member): array => [$member->sharePercent, $member->profitPart, $member->payoutTotal],
                $result->members,
            ),
        );
    }

    public function testParticipantsWithoutAnUnknownContributionKeepTheirKnownCostsWhileTheOneCarryingItHasAnUnknownCostAndJobInstallDetail(): void
    {
        $ursula = $this->member('Ursula Kane', self::URSULA_MEMBER_ID);
        $valdis = $this->member('Valdis Orn', self::VALDIS_MEMBER_ID);
        $wenzel = $this->member('Wenzel Roth', self::WENZEL_MEMBER_ID);

        $result = $this->distributionOf([
            $this->contribution($ursula, ContributionType::Material, 3_000.0),
            $this->contribution($valdis, ContributionType::JobInstall, 1_000.0),
            $this->contribution($wenzel, ContributionType::JobInstall, null),
        ]);

        $byMemberId = [];
        foreach ($result->members as $member) {
            $byMemberId[$member->memberId] = $member;
        }
        self::assertSame([3_000.0, 3_000.0], [$byMemberId[self::URSULA_MEMBER_ID]->totalCostsEngaged, $byMemberId[self::URSULA_MEMBER_ID]->materialCosts]);
        self::assertSame([1_000.0, 1_000.0], [$byMemberId[self::VALDIS_MEMBER_ID]->totalCostsEngaged, $byMemberId[self::VALDIS_MEMBER_ID]->jobInstallCosts]);
        self::assertNull($byMemberId[self::WENZEL_MEMBER_ID]->totalCostsEngaged);
        self::assertNull($byMemberId[self::WENZEL_MEMBER_ID]->jobInstallCosts);
    }

    private function distributionOf(array $approvedContributions): DistributionResult
    {
        $project = $this->createStub(IndustryProject::class);
        $project->method('getBrokerFeePercent')->willReturn(2.0);
        $project->method('getSalesTaxPercent')->willReturn(3.0);
        $this->saleRepository->method('findBy')->willReturn([(new IndustryProjectSale())->setTotalPrice(5_000.0)]);
        $this->contributionRepository->method('findApprovedByProject')->willReturn($approvedContributions);

        return $this->service->calculateDistribution($project);
    }

    private function totalsOf(DistributionResult $result): array
    {
        return [$result->totalRevenue, $result->brokerFee, $result->salesTax, $result->netRevenue, $result->totalProjectCost, $result->marginPercent];
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
