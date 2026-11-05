<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Industry\Project;

use ApiPlatform\Metadata\GetCollection;
use App\Repository\IndustryProjectContributionRepository;
use App\State\Provider\Industry\Project\ProjectContributionCollectionProvider;
use App\State\Provider\Industry\Project\ProjectResourceMapper;
use App\State\Provider\Industry\Project\ProjectParticipantCollectionProvider;
use App\State\Provider\Industry\Project\ProjectAccessChecker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Uid\Uuid;

#[CoversClass(ProjectParticipantCollectionProvider::class)]
#[CoversClass(ProjectContributionCollectionProvider::class)]
class MemberAndContributionListAccessTest extends TestCase
{
    use GroupProjectAccessProfiles;

    private Security&Stub $security;
    private ProjectParticipantCollectionProvider $memberListProvider;
    private ProjectContributionCollectionProvider $contributionListProvider;

    protected function setUp(): void
    {
        $this->createSabreBatch();
        $this->security = $this->createStub(Security::class);

        $memberRepository = $this->sabreBatchMemberRepository();
        $memberRepository->method('findBy')->willReturn([]);
        $contributionRepository = $this->createStub(IndustryProjectContributionRepository::class);
        $contributionRepository->method('findBy')->willReturn([]);
        $contributionRepository->method('findByProjectForListing')->willReturn([]);
        $accessChecker = new ProjectAccessChecker($memberRepository);

        $this->memberListProvider = new ProjectParticipantCollectionProvider(
            $this->security,
            $this->sabreBatchProjectRepository(),
            $memberRepository,
            $contributionRepository,
            $accessChecker,
            new ProjectResourceMapper(),
        );
        $this->contributionListProvider = new ProjectContributionCollectionProvider(
            $this->security,
            $this->sabreBatchProjectRepository(),
            $contributionRepository,
            $accessChecker,
            new ProjectResourceMapper(),
        );
    }

    #[DataProvider('acceptedMemberAccessMatrix')]
    public function testMemberListIsReservedToTheOwnerAndAcceptedMembers(string $profile, string $expectedOutcome): void
    {
        $this->security->method('getUser')->willReturn($this->userWithProfile($profile));

        self::assertSame($expectedOutcome, $this->outcomeOf(fn () => $this->memberListProvider->provide(new GetCollection(), $this->sabreBatchUriVariables())));
    }

    #[DataProvider('acceptedMemberAccessMatrix')]
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
