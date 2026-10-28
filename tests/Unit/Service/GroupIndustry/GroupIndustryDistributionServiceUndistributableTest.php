<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\GroupIndustry;

use App\Entity\Character;
use App\Entity\GroupIndustryContribution;
use App\Entity\GroupIndustryProject;
use App\Entity\GroupIndustryProjectMember;
use App\Entity\GroupIndustrySale;
use App\Entity\User;
use App\Enum\ContributionType;
use App\Repository\GroupIndustryContributionRepository;
use App\Repository\GroupIndustrySaleRepository;
use App\Service\GroupIndustry\DistributionResult;
use App\Service\GroupIndustry\GroupIndustryDistributionService;
use App\Service\GroupIndustry\MemberDistribution;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(GroupIndustryDistributionService::class)]
#[Group('issue-146')]
final class GroupIndustryDistributionServiceUndistributableTest extends TestCase
{
    private const string ALICE_MEMBER_ID = '00000000-0000-0000-0000-0000000001a1';
    private const string BRANN_MEMBER_ID = '00000000-0000-0000-0000-0000000001b2';

    private GroupIndustryContributionRepository&Stub $contributionRepository;
    private GroupIndustrySaleRepository&Stub $saleRepository;
    private GroupIndustryDistributionService $service;

    protected function setUp(): void
    {
        $this->contributionRepository = $this->createStub(GroupIndustryContributionRepository::class);
        $this->saleRepository = $this->createStub(GroupIndustrySaleRepository::class);
        $this->service = new GroupIndustryDistributionService($this->contributionRepository, $this->saleRepository);
    }

    public function testSaleWhoseOnlyApprovedContributionIsAFreeLineRentalHasAnUnknownMargin(): void
    {
        $alice = $this->member('Alice Kaine', self::ALICE_MEMBER_ID);

        $result = $this->distributionOf(
            $this->project(brokerFeePercent: 3.6, salesTaxPercent: 3.6),
            [1_000_000.0],
            [$this->contribution($alice, ContributionType::LineRental, 0.0)],
        );

        self::assertSame(
            [1_000_000.0, 36_000.0, 36_000.0, 928_000.0, 0.0, null],
            $this->totalsOf($result),
        );
    }

    public function testMemberOfAnUndistributableDistributionKeepsHisKnownCostsButHasNoShareNorProfitPartNorPayout(): void
    {
        $alice = $this->member('Alice Kaine', self::ALICE_MEMBER_ID);

        $result = $this->distributionOf(
            $this->project(brokerFeePercent: 3.6, salesTaxPercent: 3.6),
            [1_000_000.0],
            [$this->contribution($alice, ContributionType::LineRental, 0.0)],
        );

        self::assertSame(
            [self::ALICE_MEMBER_ID => ['Alice Kaine', 0.0, 0.0, 0.0, 0.0, 0.0, null, null, null]],
            $this->membersOf($result),
        );
    }

    public function testDistributionWithoutSaleNorContributionHasAnUnknownMargin(): void
    {
        $result = $this->distributionOf($this->project(brokerFeePercent: 3.6, salesTaxPercent: 3.6), [], []);

        self::assertSame([0.0, 0.0, 0.0, 0.0, 0.0, null], $this->totalsOf($result));
        self::assertSame([], $result->members);
    }

    public function testEveryMemberOfAnUndistributableDistributionHasAnUnknownShareProfitPartAndPayout(): void
    {
        $alice = $this->member('Alice Kaine', self::ALICE_MEMBER_ID);
        $brann = $this->member('Brann Holt', self::BRANN_MEMBER_ID);

        $result = $this->distributionOf(
            $this->project(brokerFeePercent: 2.0, salesTaxPercent: 1.0),
            [750_000.0],
            [
                $this->contribution($alice, ContributionType::LineRental, 0.0),
                $this->contribution($brann, ContributionType::Material, 0.0),
            ],
        );

        self::assertSame([750_000.0, 15_000.0, 7_500.0, 727_500.0, 0.0, null], $this->totalsOf($result));
        self::assertSame(
            [
                self::ALICE_MEMBER_ID => ['Alice Kaine', 0.0, 0.0, 0.0, 0.0, 0.0, null, null, null],
                self::BRANN_MEMBER_ID => ['Brann Holt', 0.0, 0.0, 0.0, 0.0, 0.0, null, null, null],
            ],
            $this->membersOf($result),
        );
    }

    public function testFreeLineRentalNextToAPricedMaterialHasAKnownZeroShareAndPayout(): void
    {
        $alice = $this->member('Alice Kaine', self::ALICE_MEMBER_ID);
        $brann = $this->member('Brann Holt', self::BRANN_MEMBER_ID);

        $result = $this->distributionOf(
            $this->project(brokerFeePercent: 0.0, salesTaxPercent: 0.0),
            [2_000_000.0],
            [
                $this->contribution($alice, ContributionType::LineRental, 0.0),
                $this->contribution($brann, ContributionType::Material, 1_000_000.0),
            ],
        );

        self::assertSame([2_000_000.0, 0.0, 0.0, 2_000_000.0, 1_000_000.0, 100.0], $this->totalsOf($result));
        self::assertSame(
            [
                self::ALICE_MEMBER_ID => ['Alice Kaine', 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0],
                self::BRANN_MEMBER_ID => ['Brann Holt', 1_000_000.0, 1_000_000.0, 0.0, 0.0, 0.0, 100.0, 1_000_000.0, 2_000_000.0],
            ],
            $this->membersOf($result),
        );
    }

    public function testOneIskOfApprovedCostIsEnoughToDistributeTheNetRevenue(): void
    {
        $alice = $this->member('Alice Kaine', self::ALICE_MEMBER_ID);

        $result = $this->distributionOf(
            $this->project(brokerFeePercent: 0.0, salesTaxPercent: 0.0),
            [1_000.0],
            [$this->contribution($alice, ContributionType::JobInstall, 1.0)],
        );

        self::assertSame([1_000.0, 0.0, 0.0, 1_000.0, 1.0, 99_900.0], $this->totalsOf($result));
        self::assertSame(
            [self::ALICE_MEMBER_ID => ['Alice Kaine', 1.0, 0.0, 1.0, 0.0, 0.0, 100.0, 999.0, 1_000.0]],
            $this->membersOf($result),
        );
    }

    private function distributionOf(GroupIndustryProject $project, array $saleTotals, array $approvedContributions): DistributionResult
    {
        $this->saleRepository->method('findBy')->willReturn(array_map(
            static fn (float $totalPrice): GroupIndustrySale => (new GroupIndustrySale())->setTotalPrice($totalPrice),
            $saleTotals,
        ));
        $this->contributionRepository->method('findApprovedByProject')->willReturn($approvedContributions);

        return $this->service->calculateDistribution($project);
    }

    private function project(float $brokerFeePercent, float $salesTaxPercent): GroupIndustryProject
    {
        return (new GroupIndustryProject())
            ->setName('Sabre batch')
            ->setBrokerFeePercent($brokerFeePercent)
            ->setSalesTaxPercent($salesTaxPercent);
    }

    private function member(string $characterName, string $memberId): GroupIndustryProjectMember
    {
        $user = new User();
        $character = (new Character())->setName($characterName);
        $user->addCharacter($character);
        $user->setMainCharacter($character);

        $member = (new GroupIndustryProjectMember())->setUser($user);
        (new \ReflectionProperty(GroupIndustryProjectMember::class, 'id'))->setValue($member, Uuid::fromString($memberId));

        return $member;
    }

    private function contribution(GroupIndustryProjectMember $member, ContributionType $type, float $estimatedValue): GroupIndustryContribution
    {
        return (new GroupIndustryContribution())
            ->setMember($member)
            ->setType($type)
            ->setQuantity(1)
            ->setEstimatedValue($estimatedValue);
    }

    private function totalsOf(DistributionResult $result): array
    {
        return [$result->totalRevenue, $result->brokerFee, $result->salesTax, $result->netRevenue, $result->totalProjectCost, $result->marginPercent];
    }

    private function membersOf(DistributionResult $result): array
    {
        $members = [];
        foreach ($result->members as $member) {
            \assert($member instanceof MemberDistribution);
            $members[$member->memberId] = [
                $member->characterName,
                $member->totalCostsEngaged,
                $member->materialCosts,
                $member->jobInstallCosts,
                $member->bpcCosts,
                $member->lineRentalCosts,
                $member->sharePercent,
                $member->profitPart,
                $member->payoutTotal,
            ];
        }

        return $members;
    }
}
