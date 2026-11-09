<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Industry\Project;

use ApiPlatform\Metadata\GetCollection;
use App\ApiResource\Industry\Project\ProjectBomLineResource;
use App\Entity\Character;
use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectJobMatch;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectParticipant;
use App\Entity\User;
use App\Enum\ParticipantRole;
use App\Enum\ParticipantStatus;
use App\Repository\IndustryProjectBomLineRepository;
use App\Repository\IndustryProjectLineReservationRepository;
use App\Repository\IndustryProjectParticipantRepository;
use App\Repository\IndustryProjectRepository;
use App\State\Provider\Industry\Project\ProjectBomProvider;
use App\Service\Industry\Project\ProjectJobLocationResolver;
use App\State\Provider\Industry\Project\ProjectAccessChecker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Uid\Uuid;

#[CoversClass(ProjectBomProvider::class)]
class ProjectBomProviderTest extends TestCase
{
    use GroupProjectAccessProfiles;

    private Security&Stub $security;
    private IndustryProjectRepository&Stub $projectRepository;
    private IndustryProjectBomLineRepository&Stub $bomItemRepository;
    private IndustryProjectParticipantRepository&Stub $memberRepository;
    private ProjectBomProvider $provider;

    protected function setUp(): void
    {
        $this->security = $this->createStub(Security::class);
        $this->projectRepository = $this->createStub(IndustryProjectRepository::class);
        $this->bomItemRepository = $this->createStub(IndustryProjectBomLineRepository::class);
        $this->memberRepository = $this->createStub(IndustryProjectParticipantRepository::class);

        $this->provider = new ProjectBomProvider(
            $this->security,
            $this->projectRepository,
            $this->bomItemRepository,
            new ProjectAccessChecker($this->memberRepository),
            $this->createStub(IndustryProjectLineReservationRepository::class),
            $this->createStub(ProjectJobLocationResolver::class),
        );
    }

    // ===========================================
    // Auth
    // ===========================================

    public function testThrowsUnauthorizedWhenNoUser(): void
    {
        $this->security->method('getUser')->willReturn(null);

        $this->expectException(UnauthorizedHttpException::class);

        $this->provider->provide(new GetCollection(), ['projectId' => Uuid::v4()->toString()]);
    }

    public function testThrowsNotFoundWhenProjectDoesNotExist(): void
    {
        $user = $this->createUserStub();
        $this->security->method('getUser')->willReturn($user);
        $this->projectRepository->method('find')->willReturn(null);

        $this->expectException(NotFoundHttpException::class);

        $this->provider->provide(new GetCollection(), ['projectId' => Uuid::v4()->toString()]);
    }

    public function testThrowsAccessDeniedWhenUserIsNotMemberNorSameCorp(): void
    {
        $owner = $this->createUserStub(98000001);
        $user = $this->createUserStub(98000099); // Different corp

        $this->security->method('getUser')->willReturn($user);

        $project = (new IndustryProject());
        $project->setOwner($owner);
        $this->projectRepository->method('find')->willReturn($project);
        $this->memberRepository->method('findOneBy')->willReturn(null);

        $this->expectException(AccessDeniedHttpException::class);

        $this->provider->provide(new GetCollection(), ['projectId' => Uuid::v4()->toString()]);
    }

    // ===========================================
    // Access grants
    // ===========================================

    public function testOwnerCanAccessBom(): void
    {
        $user = $this->createUserStub();
        $this->security->method('getUser')->willReturn($user);

        $project = (new IndustryProject());
        $project->setOwner($user);
        $this->projectRepository->method('find')->willReturn($project);
        $this->bomItemRepository->method('findBy')->willReturn([]);

        $result = $this->provider->provide(new GetCollection(), ['projectId' => Uuid::v4()->toString()]);

        $this->assertSame([], $result);
    }

    public function testAcceptedMemberCanAccessBom(): void
    {
        $owner = $this->createUserStub(98000001);
        $user = $this->createUserStub(98000099);

        $this->security->method('getUser')->willReturn($user);

        $project = (new IndustryProject());
        $project->setOwner($owner);
        $this->projectRepository->method('find')->willReturn($project);

        $member = new IndustryProjectParticipant();
        $member->setUser($user);
        $member->setProject($project);
        $member->setRole(ParticipantRole::Member);
        $member->setStatus(ParticipantStatus::Accepted);
        $this->memberRepository->method('findOneBy')->willReturn($member);
        $this->bomItemRepository->method('findBy')->willReturn([]);

        $result = $this->provider->provide(new GetCollection(), ['projectId' => Uuid::v4()->toString()]);

        $this->assertSame([], $result);
    }

    public function testCorpmateOfTheOwnerWhoIsNotAMemberIsRefusedTheBom(): void
    {
        $owner = $this->createUserStub(98000001);
        $corpmate = $this->createUserStub(98000001);

        $this->security->method('getUser')->willReturn($corpmate);

        $project = (new IndustryProject());
        $project->setOwner($owner);
        $this->projectRepository->method('find')->willReturn($project);
        $this->memberRepository->method('findOneBy')->willReturn(null);
        $this->bomItemRepository->method('findBy')->willReturn([]);

        $this->expectException(AccessDeniedHttpException::class);
        $this->expectExceptionMessage('You do not have access to this project');

        $this->provider->provide(new GetCollection(), ['projectId' => Uuid::v4()->toString()]);
    }

    // ===========================================
    // BOM mapping
    // ===========================================

    #[Group('issue-339')]
    public function testJobMatchesShowTheMainCharacterOfTheOwnerToAnotherPlayerAndTheAltToTheOwner(): void
    {
        $main = (new Character())->setEveCharacterId(2120000001)->setName('O-Main');
        $alt = (new Character())->setEveCharacterId(2120000002)->setName('O-Alt');
        $owner = (new User())->addCharacter($main)->addCharacter($alt)->setMainCharacter($main);
        $member = $this->createUserStub(98000099);
        $project = (new IndustryProject())->setOwner($owner);
        $this->projectRepository->method('find')->willReturn($project);
        $participant = (new IndustryProjectParticipant())->setUser($member)->setProject($project)->setRole(ParticipantRole::Member)->setStatus(ParticipantStatus::Accepted);
        $this->memberRepository->method('findOneBy')->willReturn($participant);
        $line = $this->createBomItem(34, 'Hammerhead II', 10, 0, null, true, 'final', 'manufacturing', null, 0, 0, 10);
        $line->addJobMatch((new IndustryProjectJobMatch())->setEsiJobId(1)->setStatus('active')->setRuns(10)->setCharacterName('O-Alt'));
        $this->bomItemRepository->method('findBy')->willReturn([$line]);
        $installersByViewer = [];

        foreach ([$member, $owner] as $viewer) {
            $security = $this->createStub(Security::class);
            $security->method('getUser')->willReturn($viewer);
            $provider = new ProjectBomProvider($security, $this->projectRepository, $this->bomItemRepository, new ProjectAccessChecker($this->memberRepository), $this->createStub(IndustryProjectLineReservationRepository::class), $this->createStub(ProjectJobLocationResolver::class));
            $installersByViewer[] = $provider->provide(new GetCollection(), ['projectId' => Uuid::v4()->toString()])[0]->jobMatches[0]['characterName'];
        }

        $this->assertSame(['O-Main', 'O-Alt'], $installersByViewer);
    }


    public function testMapsBomItemToResource(): void
    {
        $user = $this->createUserStub();
        $this->security->method('getUser')->willReturn($user);

        $project = (new IndustryProject());
        $project->setOwner($user);
        $this->projectRepository->method('find')->willReturn($project);

        $bomItem = $this->createBomItem(34, 'Tritanium', 1000, 500, 5.5, false, null, null, null, null, null, null);
        $this->bomItemRepository->method('findBy')->willReturn([$bomItem]);

        $result = $this->provider->provide(new GetCollection(), ['projectId' => Uuid::v4()->toString()]);

        $this->assertCount(1, $result);
        $resource = $result[0];
        $this->assertInstanceOf(ProjectBomLineResource::class, $resource);
        $this->assertSame(34, $resource->typeId);
        $this->assertSame('Tritanium', $resource->typeName);
        $this->assertSame(1000, $resource->requiredQuantity);
        $this->assertSame(500, $resource->fulfilledQuantity);
        $this->assertSame(500, $resource->remainingQuantity);
        $this->assertSame(50.0, $resource->fulfillmentPercent);
        $this->assertSame(5.5, $resource->estimatedPrice);
        $this->assertSame(5500.0, $resource->estimatedTotal); // 5.5 * 1000
        $this->assertFalse($resource->isJob);
        $this->assertNull($resource->jobGroup);
        $this->assertFalse($resource->isFulfilled);
    }

    public function testMapsJobBomItemToResource(): void
    {
        $user = $this->createUserStub();
        $this->security->method('getUser')->willReturn($user);

        $project = (new IndustryProject());
        $project->setOwner($user);
        $this->projectRepository->method('find')->willReturn($project);

        $bomItem = $this->createBomItem(
            11399, 'Ferox', 100, 0, null,
            true, 'final', 'manufacturing', 34, 10, 20, 100,
        );
        $this->bomItemRepository->method('findBy')->willReturn([$bomItem]);

        $result = $this->provider->provide(new GetCollection(), ['projectId' => Uuid::v4()->toString()]);

        $resource = $result[0];
        $this->assertTrue($resource->isJob);
        $this->assertSame('final', $resource->jobGroup);
        $this->assertSame('manufacturing', $resource->activityType);
        $this->assertSame(34, $resource->parentTypeId);
        $this->assertSame(10, $resource->meLevel);
        $this->assertSame(20, $resource->teLevel);
        $this->assertSame(100, $resource->runs);
        $this->assertNull($resource->estimatedTotal); // No price for jobs
    }

    public function testEstimatedTotalIsNullWhenNoPriceSet(): void
    {
        $user = $this->createUserStub();
        $this->security->method('getUser')->willReturn($user);

        $project = (new IndustryProject());
        $project->setOwner($user);
        $this->projectRepository->method('find')->willReturn($project);

        $bomItem = $this->createBomItem(34, 'Tritanium', 1000, 0, null, false, null, null, null, null, null, null);
        $this->bomItemRepository->method('findBy')->willReturn([$bomItem]);

        $result = $this->provider->provide(new GetCollection(), ['projectId' => Uuid::v4()->toString()]);

        $this->assertNull($result[0]->estimatedTotal);
    }

    #[DataProvider('acceptedMemberAccessMatrix')]
    public function testBomIsReadByTheOwnerAndAcceptedMembersOnly(string $profile, string $expectedOutcome): void
    {
        $this->createSabreBatch();
        $this->security->method('getUser')->willReturn($this->userWithProfile($profile));

        self::assertSame($expectedOutcome, $this->readSabreBatchBom($this->sabreBatch->getId()));
    }

    public function testBomOfAnUnknownProjectIsNotFound(): void
    {
        $this->createSabreBatch();
        $this->security->method('getUser')->willReturn($this->userOfCorporation(self::FOREIGN_CORPORATION_ID));

        self::assertSame(self::PROJECT_NOT_FOUND, $this->readSabreBatchBom(Uuid::v4()));
    }

    public function testBomListsOnlyTheItemsOfTheRequestedProject(): void
    {
        $this->createSabreBatch();
        $this->security->method('getUser')->willReturn($this->sabreBatchOwner);
        $ferox = $this->createBomItem(11399, 'Ferox', 7, 2, null, true, 'final', 'manufacturing', null, 10, 20, 7);
        $tritanium = $this->createBomItem(34, 'Tritanium', 150, 40, null, false, null, null, 11399, null, null, null);
        $bomItemRepository = $this->createStub(IndustryProjectBomLineRepository::class);
        $bomItemRepository->method('findBy')->willReturnCallback(
            fn (array $criteria): array => $criteria === ['project' => $this->sabreBatch] ? [$ferox, $tritanium] : [],
        );
        $provider = new ProjectBomProvider(
            $this->security,
            $this->sabreBatchProjectRepository(),
            $bomItemRepository,
            new ProjectAccessChecker($this->sabreBatchMemberRepository()),
            $this->createStub(IndustryProjectLineReservationRepository::class),
            $this->createStub(ProjectJobLocationResolver::class),
        );

        $result = $provider->provide(new GetCollection(), ['projectId' => $this->sabreBatch->getId()->toRfc4122()]);

        self::assertSame(['Ferox', 'Tritanium'], array_map(fn (ProjectBomLineResource $item) => $item->typeName, $result));
    }

    private function readSabreBatchBom(Uuid $projectId): string
    {
        $bomItemRepository = $this->createStub(IndustryProjectBomLineRepository::class);
        $bomItemRepository->method('findBy')->willReturn([
            $this->createBomItem(11399, 'Ferox', 7, 2, null, true, 'final', 'manufacturing', null, 10, 20, 7),
        ]);
        $provider = new ProjectBomProvider(
            $this->security,
            $this->sabreBatchProjectRepository(),
            $bomItemRepository,
            new ProjectAccessChecker($this->sabreBatchMemberRepository()),
            $this->createStub(IndustryProjectLineReservationRepository::class),
            $this->createStub(ProjectJobLocationResolver::class),
        );

        return $this->outcomeOf(fn () => $provider->provide(new GetCollection(), ['projectId' => $projectId->toRfc4122()]));
    }

    // ===========================================
    // Helpers
    // ===========================================

    private function createUserStub(?int $corporationId = null): User&Stub
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(Uuid::v4());
        $user->method('getCorporationId')->willReturn($corporationId);

        return $user;
    }

    private function createBomItem(
        int $typeId,
        string $typeName,
        int $requiredQuantity,
        int $fulfilledQuantity,
        ?float $estimatedPrice,
        bool $isJob,
        ?string $jobGroup,
        ?string $activityType,
        ?int $parentTypeId,
        ?int $meLevel,
        ?int $teLevel,
        ?int $runs,
    ): IndustryProjectBomLine {
        $item = new IndustryProjectBomLine();
        $item->setTypeId($typeId);
        $item->setTypeName($typeName);
        $item->setRequiredQuantity($requiredQuantity);
        $item->setFulfilledQuantity($fulfilledQuantity);
        $item->setEstimatedPrice($estimatedPrice);
        $item->setIsJob($isJob);
        $item->setJobGroup($jobGroup);
        $item->setActivityType($activityType);
        $item->setParentTypeId($parentTypeId);
        $item->setMeLevel($meLevel);
        $item->setTeLevel($teLevel);
        $item->setRuns($runs);

        $reflection = new \ReflectionProperty(IndustryProjectBomLine::class, 'id');
        $reflection->setValue($item, Uuid::v4());

        return $item;
    }
}
