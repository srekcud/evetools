<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\GroupIndustry;

use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Post;
use App\ApiResource\GroupIndustry\GroupIndustryContributionResource;
use App\ApiResource\Input\GroupIndustry\SubmitContributionInput;
use App\Entity\Character;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectContribution;
use App\Entity\IndustryProjectParticipant;
use App\Entity\User;
use App\Enum\GroupMemberRole;
use App\Enum\GroupMemberStatus;
use App\Repository\IndustryProjectBomLineRepository;
use App\Repository\IndustryProjectContributionRepository;
use App\Repository\IndustryProjectParticipantRepository;
use App\Repository\IndustryProjectRepository;
use App\Service\GroupIndustry\GroupIndustryContributionService;
use App\Service\Mercure\MercurePublisherService;
use App\State\Processor\GroupIndustry\SubmitContributionProcessor;
use App\State\Provider\GroupIndustry\GroupIndustryResourceMapper;
use App\State\Provider\GroupIndustry\GroupProjectDeleteProvider;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Uid\Uuid;

#[CoversClass(SubmitContributionProcessor::class)]
#[CoversClass(GroupIndustryContributionService::class)]
#[AllowMockObjectsWithoutExpectations]
#[Group('issue-362')]
class SubmitSoloContributionProcessorTest extends TestCase
{
    private const int ZYDRINE_TYPE_ID = 39;
    private const float ZYDRINE_BOM_PRICE = 1000.0;
    private const string OWNER_PILOT_NAME = 'Ilsa Verhoeven';

    private EntityManagerInterface&MockObject $entityManager;
    private SubmitContributionProcessor $processor;
    private IndustryProject $project;
    private IndustryProjectBomLine $zydrine;
    private User $owner;
    private ?IndustryProjectContribution $persistedContribution = null;

    protected function setUp(): void
    {
        $this->owner = new User();
        $ownerMain = (new Character())->setEveCharacterId(2120000101)->setName(self::OWNER_PILOT_NAME);
        $this->owner->addCharacter($ownerMain)->setMainCharacter($ownerMain);

        $this->project = new IndustryProject();
        $this->project->setOwner($this->owner);
        $this->assignId($this->project);
        $this->project->enrolOwner();
        $this->assignId($this->project->getMembers()->first());

        $this->zydrine = new IndustryProjectBomLine();
        $this->zydrine->setTypeId(self::ZYDRINE_TYPE_ID);
        $this->zydrine->setTypeName('Zydrine');
        $this->zydrine->setRequiredQuantity(25);
        $this->zydrine->setEstimatedPrice(self::ZYDRINE_BOM_PRICE);
        $this->assignId($this->zydrine);
        $this->project->addBomItem($this->zydrine);

        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($this->owner);
        $projectRepository = $this->createStub(IndustryProjectRepository::class);
        $projectRepository->method('find')->willReturn($this->project);
        $memberRepository = $this->createStub(IndustryProjectParticipantRepository::class);
        $memberRepository->method('findOneBy')->willReturnCallback(
            function (array $criteria): ?IndustryProjectParticipant {
                foreach ($this->project->getMembers() as $member) {
                    if ($member->getUser() === $criteria['user'] && $member->getStatus() === $criteria['status']) {
                        return $member;
                    }
                }

                return null;
            },
        );
        $bomItemRepository = $this->createStub(IndustryProjectBomLineRepository::class);
        $bomItemRepository->method('find')->willReturn($this->zydrine);
        $contributionRepository = $this->createStub(IndustryProjectContributionRepository::class);
        $contributionRepository->method('findByMemberBomItemAndType')->willReturn(null);

        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->entityManager->method('persist')->willReturnCallback(
            function (object $entity): void {
                if ($entity instanceof IndustryProjectContribution) {
                    $this->assignId($entity);
                    $this->project->addContribution($entity);
                    $this->persistedContribution = $entity;
                }
            },
        );

        $this->processor = new SubmitContributionProcessor(
            $security,
            $projectRepository,
            $memberRepository,
            $bomItemRepository,
            new GroupIndustryContributionService($contributionRepository, $this->entityManager),
            new GroupIndustryResourceMapper(),
            new MercurePublisherService($this->createStub(HubInterface::class), new NullLogger()),
        );
    }

    public function testContributionOfTheOwnerOfAProjectWithoutAnotherAcceptedParticipantIsApprovedAtSubmissionAndServesTheBomLine(): void
    {
        $before = new \DateTimeImmutable();

        $resource = $this->submitZydrine(10);

        $after = new \DateTimeImmutable();
        $contribution = $this->persistedContribution;
        self::assertNotNull($contribution);
        self::assertSame(
            ['material', 10, 10000.0, 'approved', self::OWNER_PILOT_NAME, 10],
            [
                $resource->type,
                $resource->quantity,
                $resource->estimatedValue,
                $resource->status,
                $resource->reviewedByCharacterName,
                $this->zydrine->getFulfilledQuantity(),
            ],
        );
        self::assertSame($this->owner, $contribution->getReviewedBy());
        self::assertNotNull($contribution->getReviewedAt());
        self::assertGreaterThanOrEqual($before, $contribution->getReviewedAt());
        self::assertLessThanOrEqual($after, $contribution->getReviewedAt());
    }

    public function testContributionOfTheOwnerStaysPendingWhenAnotherParticipantIsAccepted(): void
    {
        $this->addParticipant(GroupMemberRole::Member, GroupMemberStatus::Accepted);

        $resource = $this->submitZydrine(10);

        self::assertSame(
            ['pending', 10000.0, null, 0],
            [
                $resource->status,
                $resource->estimatedValue,
                $resource->reviewedByCharacterName,
                $this->zydrine->getFulfilledQuantity(),
            ],
        );
        self::assertNull($this->persistedContribution?->getReviewedBy());
        self::assertNull($this->persistedContribution?->getReviewedAt());
    }

    #[DataProvider('participantStatusesThatDoNotCount')]
    public function testContributionOfTheOwnerIsApprovedWhenTheOtherParticipantIsNotAccepted(GroupMemberStatus $status): void
    {
        $this->addParticipant(GroupMemberRole::Member, $status);

        $resource = $this->submitZydrine(6);

        self::assertSame(
            ['approved', 6000.0, 6],
            [$resource->status, $resource->estimatedValue, $this->zydrine->getFulfilledQuantity()],
        );
    }

    public static function participantStatusesThatDoNotCount(): iterable
    {
        yield 'pending' => [GroupMemberStatus::Pending];
        yield 'removed' => [GroupMemberStatus::Removed];
    }

    public function testSoloProjectIsDeletableUntilTheOwnerContributionIsApprovedThenDeletionIsABadRequest(): void
    {
        $deleteProvider = $this->deleteProvider();
        $deletionVariables = ['id' => $this->project->getId()->toRfc4122()];

        $beforeContribution = $deleteProvider->provide(new Delete(), $deletionVariables);
        self::assertSame($this->project->getId()->toString(), $beforeContribution->id);

        $this->submitZydrine(10);

        $this->expectException(BadRequestHttpException::class);

        $deleteProvider->provide(new Delete(), $deletionVariables);
    }

    private function submitZydrine(int $quantity): GroupIndustryContributionResource
    {
        $input = new SubmitContributionInput();
        $input->type = 'material';
        $input->bomItemId = $this->zydrine->getId()->toRfc4122();
        $input->quantity = $quantity;

        return $this->processor->process($input, new Post(), [
            'projectId' => $this->project->getId()->toRfc4122(),
        ]);
    }

    private function addParticipant(GroupMemberRole $role, GroupMemberStatus $status): void
    {
        $participant = new IndustryProjectParticipant();
        $participant->setUser(new User());
        $participant->setRole($role);
        $participant->setStatus($status);
        $this->assignId($participant);
        $this->project->addMember($participant);
    }

    private function deleteProvider(): GroupProjectDeleteProvider
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($this->owner);
        $projectRepository = $this->createStub(IndustryProjectRepository::class);
        $projectRepository->method('find')->willReturn($this->project);

        return new GroupProjectDeleteProvider($security, $projectRepository);
    }

    private function assignId(object $entity): void
    {
        $property = new \ReflectionProperty($entity, 'id');
        $property->setValue($entity, Uuid::v4());
    }
}
