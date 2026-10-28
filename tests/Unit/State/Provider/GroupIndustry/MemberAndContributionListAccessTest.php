<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\GroupIndustry;

use ApiPlatform\Metadata\GetCollection;
use App\Repository\GroupIndustryContributionRepository;
use App\State\Provider\GroupIndustry\GroupContributionCollectionProvider;
use App\State\Provider\GroupIndustry\GroupIndustryResourceMapper;
use App\State\Provider\GroupIndustry\GroupMemberCollectionProvider;
use App\State\Provider\GroupIndustry\GroupProjectAccessChecker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Uid\Uuid;

#[CoversClass(GroupMemberCollectionProvider::class)]
#[CoversClass(GroupContributionCollectionProvider::class)]
class MemberAndContributionListAccessTest extends TestCase
{
    use GroupProjectAccessProfiles;

    private Security&Stub $security;
    private GroupMemberCollectionProvider $memberListProvider;
    private GroupContributionCollectionProvider $contributionListProvider;

    protected function setUp(): void
    {
        $this->createSabreBatch();
        $this->security = $this->createStub(Security::class);

        $memberRepository = $this->sabreBatchMemberRepository();
        $memberRepository->method('findBy')->willReturn([]);
        $contributionRepository = $this->createStub(GroupIndustryContributionRepository::class);
        $contributionRepository->method('findBy')->willReturn([]);
        $contributionRepository->method('findByProjectForListing')->willReturn([]);
        $accessChecker = new GroupProjectAccessChecker($memberRepository);

        $this->memberListProvider = new GroupMemberCollectionProvider(
            $this->security,
            $this->sabreBatchProjectRepository(),
            $memberRepository,
            $contributionRepository,
            $accessChecker,
            new GroupIndustryResourceMapper(),
        );
        $this->contributionListProvider = new GroupContributionCollectionProvider(
            $this->security,
            $this->sabreBatchProjectRepository(),
            $contributionRepository,
            $accessChecker,
            new GroupIndustryResourceMapper(),
        );
    }

    #[DataProvider('participationAccessMatrix')]
    public function testMemberListIsReservedToTheOwnerAndAcceptedMembers(string $profile, string $expectedOutcome): void
    {
        $this->security->method('getUser')->willReturn($this->userWithProfile($profile));

        self::assertSame($expectedOutcome, $this->outcomeOf(fn () => $this->memberListProvider->provide(new GetCollection(), $this->sabreBatchUriVariables())));
    }

    #[DataProvider('participationAccessMatrix')]
    public function testContributionListIsReservedToTheOwnerAndAcceptedMembers(string $profile, string $expectedOutcome): void
    {
        $this->security->method('getUser')->willReturn($this->userWithProfile($profile));

        self::assertSame($expectedOutcome, $this->outcomeOf(fn () => $this->contributionListProvider->provide(new GetCollection(), $this->sabreBatchUriVariables())));
    }

    public function testListsOfAnUnknownProjectAreNotFound(): void
    {
        $this->security->method('getUser')->willReturn($this->userOfCorporation(self::FOREIGN_CORPORATION_ID));
        $unknownProject = ['projectId' => Uuid::v4()->toRfc4122()];

        self::assertSame(
            [self::PROJECT_NOT_FOUND, self::PROJECT_NOT_FOUND],
            [
                $this->outcomeOf(fn () => $this->memberListProvider->provide(new GetCollection(), $unknownProject)),
                $this->outcomeOf(fn () => $this->contributionListProvider->provide(new GetCollection(), $unknownProject)),
            ],
        );
    }

    private function sabreBatchUriVariables(): array
    {
        return ['projectId' => $this->sabreBatch->getId()->toRfc4122()];
    }
}
