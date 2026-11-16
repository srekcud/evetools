<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\Industry\Project;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\ApiResource\Industry\Project\ProjectContributionResource;
use App\ApiResource\Input\Industry\Project\AllocateUnclaimedStockInput;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectContribution;
use App\Entity\IndustryProjectParticipant;
use App\Entity\User;
use App\Enum\ParticipantRole;
use App\Enum\ParticipantStatus;
use App\Enum\ProjectStatus;
use App\Repository\IndustryProjectBomLineRepository;
use App\Repository\IndustryProjectContributionRepository;
use App\Repository\IndustryProjectParticipantRepository;
use App\Repository\IndustryProjectRepository;
use App\Service\Industry\Project\ContributorPaidPurchaseReader;
use App\Service\Industry\Project\IndustryProjectService;
use App\Service\Industry\Project\LineReservationService;
use App\Service\Industry\Project\ProjectContributionService;
use App\Service\Industry\Project\UnclaimedStockGuard;
use App\Service\JitaMarketService;
use App\Service\Mercure\MercurePublisherService;
use App\State\Processor\Industry\Project\AllocateUnclaimedStockProcessor;
use App\State\Provider\Industry\Project\ProjectAccessChecker;
use App\State\Provider\Industry\Project\ProjectResourceMapper;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Uid\Uuid;

#[CoversClass(AllocateUnclaimedStockProcessor::class)]
#[CoversClass(AllocateUnclaimedStockInput::class)]
#[Group('issue-1050')]
#[AllowMockObjectsWithoutExpectations]
final class AllocateUnclaimedStockProcessorTest extends TestCase
{
    private const float LAUNCH_PRICE = 12.5;

    private Security&Stub $security;
    private EntityManagerInterface&MockObject $entityManager;
    private HubInterface&MockObject $hub;
    private UnclaimedStockGuard&MockObject $guard;
    private AllocateUnclaimedStockProcessor $processor;
    private User $owner;
    private User $memberUser;
    private IndustryProject $project;
    private IndustryProjectParticipant $ownerParticipant;
    private IndustryProjectParticipant $member;
    private IndustryProjectParticipant $pendingMember;
    private IndustryProjectBomLine $line;
    private ?User $actingUser = null;

    protected function setUp(): void
    {
        $this->owner = new User();
        $this->memberUser = new User();
        $this->actingUser = $this->owner;

        $this->project = (new IndustryProject())->setOwner($this->owner)->setStatus(ProjectStatus::InProgress);
        $this->assignId($this->project);
        $this->project->enrolOwner();
        $this->ownerParticipant = $this->project->getOwnerParticipant();
        $this->assignId($this->ownerParticipant);
        $this->member = $this->addParticipant($this->memberUser, ParticipantStatus::Accepted);
        $this->pendingMember = $this->addParticipant(new User(), ParticipantStatus::Pending);
        $this->line = (new IndustryProjectBomLine())->setTypeId(34)->setTypeName('Tritanium')->setRequiredQuantity(100)->setIsJob(false)->setLaunchPrice(self::LAUNCH_PRICE);
        $this->assignId($this->line);
        $this->project->addBomItem($this->line);

        $this->security = $this->createStub(Security::class);
        $this->security->method('getUser')->willReturnCallback(fn (): ?User => $this->actingUser);
        $projectRepository = $this->createStub(IndustryProjectRepository::class);
        $projectRepository->method('find')->willReturn($this->project);
        $lineRepository = $this->createStub(IndustryProjectBomLineRepository::class);
        $lineRepository->method('find')->willReturnCallback(fn (Uuid $id): ?IndustryProjectBomLine => $id->equals($this->line->getId()) ? $this->line : null);
        $participantRepository = $this->createStub(IndustryProjectParticipantRepository::class);
        $participantRepository->method('find')->willReturnCallback(
            fn (Uuid $id): ?IndustryProjectParticipant => match (true) {
                $id->equals($this->member->getId()) => $this->member,
                $id->equals($this->pendingMember->getId()) => $this->pendingMember,
                default => null,
            },
        );

        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->entityManager->method('persist')->willReturnCallback(
            function (object $entity): void {
                if ($entity instanceof IndustryProjectContribution) {
                    $this->assignId($entity);
                    $this->project->addContribution($entity);
                }
            },
        );
        $this->hub = $this->createMock(HubInterface::class);
        $this->guard = $this->createMock(UnclaimedStockGuard::class);

        $service = new ProjectContributionService(
            $this->createStub(IndustryProjectContributionRepository::class),
            $this->entityManager,
            $this->createStub(JitaMarketService::class),
            $this->createStub(LineReservationService::class),
            $this->createStub(IndustryProjectService::class),
            $this->createStub(ContributorPaidPurchaseReader::class),
        );

        $this->processor = new AllocateUnclaimedStockProcessor(
            $this->security,
            $projectRepository,
            $lineRepository,
            $participantRepository,
            new ProjectAccessChecker($participantRepository),
            $service,
            $this->guard,
            new ProjectResourceMapper(),
            new MercurePublisherService($this->hub, new NullLogger()),
        );
    }

    public function testOwnerAssignsUnclaimedStockToAParticipantAtTheLaunchPriceAndTheProjectIsNotified(): void
    {
        $payloads = $this->capturePublishedPayloads();
        $this->guard->expects($this->once())->method('assertWithin')->with($this->line, 50);

        $resource = $this->allocate('assign', 50, $this->member);

        self::assertSame([$this->member->getId()->toString(), 625.0, 'launch_price', 'approved', 50], [$resource->memberId, $resource->estimatedValue, $resource->valueSource, $resource->status, $this->line->getFulfilledQuantity()]);
        self::assertCount(1, $payloads);
        self::assertSame('unclaimed_stock_allocated', $payloads[0]['action']);
        self::assertSame(['assign', 'Tritanium', 50, $resource->id], [$payloads[0]['data']['mode'], $payloads[0]['data']['bomItemName'], $payloads[0]['data']['quantity'], $payloads[0]['data']['contributionId']]);
    }

    public function testOwnerDonatesUnclaimedStockAsAnApprovedContributionWorthNothing(): void
    {
        $payloads = $this->capturePublishedPayloads();
        $this->guard->expects($this->once())->method('assertWithin')->with($this->line, 30);

        $resource = $this->allocate('donate', 30);

        self::assertSame([$this->ownerParticipant->getId()->toString(), 0.0, 'donation', 'approved', 30], [$resource->memberId, $resource->estimatedValue, $resource->valueSource, $resource->status, $this->line->getFulfilledQuantity()]);
        self::assertSame('donate', $payloads[0]['data']['mode']);
    }

    public function testDonationIsPossibleWithoutALaunchPrice(): void
    {
        $this->line->setLaunchPrice(null);

        $resource = $this->allocate('donate', 30);

        self::assertSame(0.0, $resource->estimatedValue);
    }

    public function testNonOwnerIsRefusedEvenWhenAnAdmin(): void
    {
        $adminUser = new User();
        $this->addParticipant($adminUser, ParticipantStatus::Accepted, ParticipantRole::Admin);
        $this->guard->expects($this->never())->method('assertWithin');
        $this->entityManager->expects($this->never())->method('persist');
        $this->hub->expects($this->never())->method('publish');

        foreach ([$this->memberUser, $adminUser] as $user) {
            $this->actingUser = $user;
            try {
                $this->allocate('donate', 10);
                self::fail('The allocation was accepted');
            } catch (AccessDeniedHttpException) {
                self::assertSame(0, $this->line->getFulfilledQuantity());
            }
        }
    }

    public function testAssignmentNeedsAParticipant(): void
    {
        $this->entityManager->expects($this->never())->method('persist');

        $this->expectException(BadRequestHttpException::class);

        $this->allocate('assign', 10);
    }

    public function testAssignmentToAParticipantWhoIsNotAcceptedOrNotOfTheProjectIsRefused(): void
    {
        $this->entityManager->expects($this->never())->method('persist');

        foreach ([$this->pendingMember, (new IndustryProjectParticipant())->setUser(new User())->setStatus(ParticipantStatus::Accepted)->setRole(ParticipantRole::Member)] as $recipient) {
            $this->assignId($recipient);
            try {
                $this->allocate('assign', 10, $recipient);
                self::fail('The assignment was accepted');
            } catch (BadRequestHttpException|NotFoundHttpException) {
                self::assertSame(0, $this->line->getFulfilledQuantity());
            }
        }
    }

    public function testUnknownModeIsABadRequest(): void
    {
        $this->entityManager->expects($this->never())->method('persist');

        $this->expectException(BadRequestHttpException::class);

        $this->allocate('gift', 10);
    }

    public function testUnclaimedStockGuardRefusalIsABadRequestAndNothingIsStoredNorPublished(): void
    {
        $this->guard->method('assertWithin')->willThrowException(new \DomainException('The container stock of this project is unknown.'));
        $this->entityManager->expects($this->never())->method('persist');
        $this->hub->expects($this->never())->method('publish');

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('container stock');

        $this->allocate('assign', 10, $this->member);
    }

    public function testAssignmentWithoutALaunchPriceIsABadRequestAndNothingIsStored(): void
    {
        $this->line->setLaunchPrice(null);
        $this->entityManager->expects($this->never())->method('persist');
        $this->hub->expects($this->never())->method('publish');

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('launch price');

        $this->allocate('assign', 10, $this->member);
    }

    public function testBomLineOfAnotherProjectIsNotFound(): void
    {
        $input = $this->input('donate', 10);
        $input->bomItemId = Uuid::v4()->toRfc4122();

        $this->expectException(NotFoundHttpException::class);

        $this->processor->process($input, new Post(), ['projectId' => $this->project->getId()->toRfc4122()]);
    }

    public function testTheContributionApiDeclaresTheOwnerAllocationOperationUnderTheProject(): void
    {
        $operations = (new \ReflectionClass(ProjectContributionResource::class))->getAttributes(ApiResource::class)[0]->newInstance()->getOperations();
        $allocations = array_values(array_filter(
            iterator_to_array($operations),
            static fn (object $operation): bool => AllocateUnclaimedStockProcessor::class === $operation->getProcessor(),
        ));

        self::assertCount(1, $allocations);
        self::assertSame('/industry/projects/{projectId}/contributions/unclaimed-stock', $allocations[0]->getUriTemplate());
        self::assertArrayHasKey('projectId', $allocations[0]->getUriVariables());
        $input = $allocations[0]->getInput();
        self::assertSame(AllocateUnclaimedStockInput::class, \is_array($input) ? $input['class'] : $input);
    }

    private function allocate(string $mode, int $quantity, ?IndustryProjectParticipant $recipient = null): ProjectContributionResource
    {
        return $this->processor->process($this->input($mode, $quantity, $recipient), new Post(), ['projectId' => $this->project->getId()->toRfc4122()]);
    }

    private function input(string $mode, int $quantity, ?IndustryProjectParticipant $recipient = null): AllocateUnclaimedStockInput
    {
        $input = new AllocateUnclaimedStockInput();
        $input->bomItemId = $this->line->getId()->toRfc4122();
        $input->quantity = $quantity;
        $input->mode = $mode;
        $input->participantId = $recipient?->getId()->toRfc4122();

        return $input;
    }

    private function addParticipant(User $user, ParticipantStatus $status, ParticipantRole $role = ParticipantRole::Member): IndustryProjectParticipant
    {
        $participant = (new IndustryProjectParticipant())->setUser($user)->setRole($role)->setStatus($status);
        $this->assignId($participant);
        $this->project->addMember($participant);

        return $participant;
    }

    private function capturePublishedPayloads(): \ArrayObject
    {
        $payloads = new \ArrayObject();
        $this->hub->method('publish')->willReturnCallback(
            static function (Update $update) use ($payloads): string {
                $payloads[] = json_decode($update->getData(), true);

                return 'update-id';
            },
        );

        return $payloads;
    }

    private function assignId(object $entity): void
    {
        (new \ReflectionProperty($entity, 'id'))->setValue($entity, Uuid::v4());
    }
}
