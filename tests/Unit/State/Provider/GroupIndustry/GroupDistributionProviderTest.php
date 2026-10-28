<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\GroupIndustry;

use ApiPlatform\Metadata\Get;
use App\ApiResource\GroupIndustry\GroupIndustryDistributionResource;
use App\Service\GroupIndustry\DistributionResult;
use App\Service\GroupIndustry\GroupIndustryDistributionService;
use App\Service\GroupIndustry\MemberDistribution;
use App\State\Provider\GroupIndustry\GroupDistributionProvider;
use App\State\Provider\GroupIndustry\GroupProjectAccessChecker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Uid\Uuid;

#[CoversClass(GroupDistributionProvider::class)]
class GroupDistributionProviderTest extends TestCase
{
    use GroupProjectAccessProfiles;

    private const string ALICE_MEMBER_ID = '00000000-0000-0000-0000-0000000000a1';
    private const string DELETED_MEMBER_ID = '00000000-0000-0000-0000-0000000000d5';

    private Security&Stub $security;
    private GroupDistributionProvider $provider;

    protected function setUp(): void
    {
        $this->createSabreBatch();
        $this->security = $this->createStub(Security::class);

        $distributionService = $this->createStub(GroupIndustryDistributionService::class);
        $distributionService->method('calculateDistribution')->willReturn(new DistributionResult(
            totalRevenue: 48_750_000.0,
            brokerFee: 1_755_000.0,
            salesTax: 1_462_500.0,
            netRevenue: 45_532_500.0,
            totalProjectCost: 37_940_000.0,
            marginPercent: 20.01,
            members: [
                new MemberDistribution(
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
                new MemberDistribution(
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

        $this->provider = new GroupDistributionProvider(
            $this->security,
            $this->sabreBatchProjectRepository(),
            new GroupProjectAccessChecker($this->sabreBatchMemberRepository()),
            $distributionService,
        );
    }

    #[DataProvider('readAccessMatrix')]
    public function testDistributionIsReadByTheOwnerAcceptedMembersAndTheOwnerCorporation(string $profile, string $expectedOutcome): void
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

    private function distributionOf(Uuid $projectId): GroupIndustryDistributionResource
    {
        return $this->provider->provide(new Get(), ['projectId' => $projectId->toRfc4122()]);
    }
}
