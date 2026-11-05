<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Industry\Project;

use ApiPlatform\Metadata\Get;
use App\ApiResource\Industry\Project\ProjectDistributionResource;
use App\Entity\IndustryProjectContribution;
use App\Entity\IndustryProjectSale;
use App\Enum\ContributionStatus;
use App\Enum\ContributionType;
use App\Enum\ParticipantRole;
use App\Enum\ParticipantStatus;
use App\Repository\IndustryProjectContributionRepository;
use App\Repository\IndustryProjectSaleRepository;
use App\Service\Industry\Project\DistributionResult;
use App\Service\Industry\Project\ProjectDistributionService;
use App\Service\Industry\Project\ParticipantDistribution;
use App\State\Provider\Industry\Project\ProjectDistributionProvider;
use App\State\Provider\Industry\Project\ProjectAccessChecker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Uid\Uuid;

#[CoversClass(ProjectDistributionProvider::class)]
class ProjectDistributionProviderTest extends TestCase
{
    use GroupProjectAccessProfiles;

    private const string ALICE_MEMBER_ID = '00000000-0000-0000-0000-0000000000a1';
    private const string DELETED_MEMBER_ID = '00000000-0000-0000-0000-0000000000d5';

    private Security&Stub $security;
    private ProjectDistributionProvider $provider;

    protected function setUp(): void
    {
        $this->createSabreBatch();
        $this->security = $this->createStub(Security::class);

        $distributionService = $this->createStub(ProjectDistributionService::class);
        $distributionService->method('calculateDistribution')->willReturn(new DistributionResult(
            totalRevenue: 48_750_000.0,
            brokerFee: 1_755_000.0,
            salesTax: 1_462_500.0,
            netRevenue: 45_532_500.0,
            totalProjectCost: 37_940_000.0,
            marginPercent: 20.01,
            members: [
                new ParticipantDistribution(
                    memberId: self::ALICE_MEMBER_ID,
                    characterName: 'Alice Kaine',
                    totalCostsEngaged: 31_215_000.0,
                    materialCosts: 28_400_000.0,
                    jobInstallCosts: 1_865_000.0,
                    bpcCosts: 700_000.0,
                    lineRentalCosts: 250_000.0,
                    sharePercent: 82.27,
                    profitPart: 6_246_000.0,
                    payoutTotal: 37_461_000.0,
                ),
                new ParticipantDistribution(
                    memberId: self::DELETED_MEMBER_ID,
                    characterName: null,
                    totalCostsEngaged: 6_725_000.0,
                    materialCosts: 6_725_000.0,
                    jobInstallCosts: 0.0,
                    bpcCosts: 0.0,
                    lineRentalCosts: 0.0,
                    sharePercent: 17.73,
                    profitPart: 1_346_500.0,
                    payoutTotal: 8_071_500.0,
                ),
            ],
        ));

        $this->provider = new ProjectDistributionProvider(
            $this->security,
            $this->sabreBatchProjectRepository(),
            new ProjectAccessChecker($this->sabreBatchMemberRepository()),
            $distributionService,
        );
    }

    #[DataProvider('acceptedMemberAccessMatrix')]
    public function testDistributionIsReadByTheOwnerAndAcceptedMembersOnly(string $profile, string $expectedOutcome): void
    {
        $this->security->method('getUser')->willReturn($this->userWithProfile($profile));

        self::assertSame($expectedOutcome, $this->outcomeOf(fn () => $this->distributionOf($this->sabreBatch->getId())));
    }

    public function testDistributionOfAnUnknownProjectIsNotFound(): void
    {
        $this->security->method('getUser')->willReturn($this->userOfCorporation(self::FOREIGN_CORPORATION_ID));

        self::assertSame(self::PROJECT_NOT_FOUND, $this->outcomeOf(fn () => $this->distributionOf(Uuid::v4())));
    }

    public function testDistributionWithoutUserIsUnauthorized(): void
    {
        $this->security->method('getUser')->willReturn(null);

        self::assertSame(self::UNAUTHORIZED, $this->outcomeOf(fn () => $this->distributionOf($this->sabreBatch->getId())));
    }

    public function testDistributionCarriesTheRevenueFeesAndEveryPayoutOfTheCalculation(): void
    {
        $this->security->method('getUser')->willReturn($this->userWithProfile(self::ACCEPTED_MEMBER));

        $resource = $this->distributionOf($this->sabreBatch->getId());

        self::assertSame($this->sabreBatch->getId()->toRfc4122(), $resource->id);
        self::assertSame(
            [48_750_000.0, 1_755_000.0, 1_462_500.0, 45_532_500.0, 37_940_000.0, 20.01],
            [$resource->totalRevenue, $resource->brokerFee, $resource->salesTax, $resource->netRevenue, $resource->totalProjectCost, $resource->marginPercent],
        );
        self::assertSame(
            [
                [
                    'memberId' => self::ALICE_MEMBER_ID,
                    'characterName' => 'Alice Kaine',
                    'totalCostsEngaged' => 31_215_000.0,
                    'materialCosts' => 28_400_000.0,
                    'jobInstallCosts' => 1_865_000.0,
                    'bpcCosts' => 700_000.0,
                    'lineRentalCosts' => 250_000.0,
                    'sharePercent' => 82.27,
                    'profitPart' => 6_246_000.0,
                    'payoutTotal' => 37_461_000.0,
                ],
                [
                    'memberId' => self::DELETED_MEMBER_ID,
                    'characterName' => null,
                    'totalCostsEngaged' => 6_725_000.0,
                    'materialCosts' => 6_725_000.0,
                    'jobInstallCosts' => 0.0,
                    'bpcCosts' => 0.0,
                    'lineRentalCosts' => 0.0,
                    'sharePercent' => 17.73,
                    'profitPart' => 1_346_500.0,
                    'payoutTotal' => 8_071_500.0,
                ],
            ],
            $resource->members,
        );
    }

    #[Group('issue-146')]
    public function testUndistributableDistributionReachesTheApiWithAnUnknownMarginShareProfitPartAndPayout(): void
    {
        $viewer = $this->userWithProfile(self::ACCEPTED_MEMBER);
        $this->security->method('getUser')->willReturn($viewer);
        $this->sabreBatch->setBrokerFeePercent(3.6)->setSalesTaxPercent(3.6);
        $lineRenter = $this->addSabreBatchMembership($this->userOfCorporation(self::FOREIGN_CORPORATION_ID), ParticipantRole::Member, ParticipantStatus::Accepted);
        $freeLineRental = (new IndustryProjectContribution())
            ->setMember($lineRenter)
            ->setType(ContributionType::LineRental)
            ->setQuantity(1)
            ->setEstimatedValue(0.0)
            ->setStatus(ContributionStatus::Approved);
        $contributionRepository = $this->createStub(IndustryProjectContributionRepository::class);
        $contributionRepository->method('findApprovedByProject')->willReturn([$freeLineRental]);
        $saleRepository = $this->createStub(IndustryProjectSaleRepository::class);
        $saleRepository->method('findBy')->willReturn([(new IndustryProjectSale())->setTotalPrice(1_000_000.0)]);
        $provider = new ProjectDistributionProvider(
            $this->security,
            $this->sabreBatchProjectRepository(),
            new ProjectAccessChecker($this->sabreBatchMemberRepository()),
            new ProjectDistributionService($contributionRepository, $saleRepository),
        );

        $resource = $provider->provide(new Get(), ['projectId' => $this->sabreBatch->getId()->toRfc4122()]);

        self::assertSame(
            [1_000_000.0, 36_000.0, 36_000.0, 928_000.0, 0.0, null],
            [$resource->totalRevenue, $resource->brokerFee, $resource->salesTax, $resource->netRevenue, $resource->totalProjectCost, $resource->marginPercent],
        );
        self::assertSame(
            [
                [
                    'memberId' => $lineRenter->getId()->toRfc4122(),
                    'characterName' => 'Unknown',
                    'totalCostsEngaged' => 0.0,
                    'materialCosts' => 0.0,
                    'jobInstallCosts' => 0.0,
                    'bpcCosts' => 0.0,
                    'lineRentalCosts' => 0.0,
                    'sharePercent' => null,
                    'profitPart' => null,
                    'payoutTotal' => null,
                ],
            ],
            $resource->members,
        );
    }

    #[Group('issue-347')]
    public function testDistributionWithUnknownFeesReachesTheApiWithUnknownFeesNetRevenueMarginProfitPartAndPayout(): void
    {
        $this->security->method('getUser')->willReturn($this->userWithProfile(self::ACCEPTED_MEMBER));
        $distributionService = $this->createStub(ProjectDistributionService::class);
        $distributionService->method('calculateDistribution')->willReturn(new DistributionResult(
            totalRevenue: 12_300_000.0,
            brokerFee: null,
            salesTax: null,
            netRevenue: null,
            totalProjectCost: 9_400_000.0,
            marginPercent: null,
            members: [
                new ParticipantDistribution(
                    memberId: self::ALICE_MEMBER_ID,
                    characterName: 'Alice Kaine',
                    totalCostsEngaged: 9_400_000.0,
                    materialCosts: 9_400_000.0,
                    jobInstallCosts: 0.0,
                    bpcCosts: 0.0,
                    lineRentalCosts: 0.0,
                    sharePercent: 100.0,
                    profitPart: null,
                    payoutTotal: null,
                ),
            ],
        ));
        $provider = new ProjectDistributionProvider(
            $this->security,
            $this->sabreBatchProjectRepository(),
            new ProjectAccessChecker($this->sabreBatchMemberRepository()),
            $distributionService,
        );

        $resource = $provider->provide(new Get(), ['projectId' => $this->sabreBatch->getId()->toRfc4122()]);

        self::assertSame(
            [12_300_000.0, null, null, null, 9_400_000.0, null],
            [$resource->totalRevenue, $resource->brokerFee, $resource->salesTax, $resource->netRevenue, $resource->totalProjectCost, $resource->marginPercent],
        );
        self::assertSame(100.0, $resource->members[0]['sharePercent']);
        self::assertNull($resource->members[0]['profitPart']);
        self::assertNull($resource->members[0]['payoutTotal']);
    }

    private function distributionOf(Uuid $projectId): ProjectDistributionResource
    {
        return $this->provider->provide(new Get(), ['projectId' => $projectId->toRfc4122()]);
    }
}
