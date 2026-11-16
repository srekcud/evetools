<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\Industry\Project;

use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Patch;
use App\ApiResource\Input\Industry\Project\EditContributionInput;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectContribution;
use App\Entity\IndustryProjectParticipant;
use App\Entity\User;
use App\Enum\ContributionStatus;
use App\Enum\ContributionType;
use App\Enum\ParticipantRole;
use App\Enum\ParticipantStatus;
use App\Enum\ProjectStatus;
use App\Repository\IndustryProjectContributionRepository;
use App\Repository\IndustryProjectParticipantRepository;
use App\Repository\IndustryProjectRepository;
use App\Service\Industry\Project\ContributorPaidPurchaseReader;
use App\Service\Industry\Project\IndustryProjectService;
use App\Service\Industry\Project\LineReservationService;
use App\Service\Industry\Project\ProjectContributionService;
use App\Service\Industry\Project\UnclaimedStockGuard;
use App\Enum\ContributionValueSource;
use App\Service\JitaMarketService;
use App\Service\Mercure\MercurePublisherService;
use App\State\Processor\Industry\Project\EditContributionProcessor;
use App\State\Processor\Industry\Project\WithdrawContributionProcessor;
use App\State\Provider\Industry\Project\ProjectAccessChecker;
use App\State\Provider\Industry\Project\ProjectContributionCollectionProvider;
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

#[CoversClass(EditContributionProcessor::class)]
#[CoversClass(WithdrawContributionProcessor::class)]
#[CoversClass(ProjectResourceMapper::class)]
#[Group('issue-1012')]
#[AllowMockObjectsWithoutExpectations]
final class OwnContributionProcessorsTest extends TestCase
{
    private const int PENDING_QUANTITY = 100;
    private const int NEW_QUANTITY = 150;

    private Security&Stub $security;
    private IndustryProjectContributionRepository&Stub $contributionRepository;
    private EntityManagerInterface&MockObject $entityManager;
    private HubInterface&MockObject $hub;
    private UnclaimedStockGuard&MockObject $unclaimedStockGuard;
    private EditContributionProcessor $editProcessor;
    private WithdrawContributionProcessor $withdrawProcessor;
    private User $owner;
    private User $contributorUser;
    private IndustryProject $project;
    private IndustryProjectParticipant $contributor;
    private IndustryProjectBomLine $line;
    private ?User $actingUser = null;
    private ?IndustryProjectContribution $currentContribution = null;

    protected function setUp(): void
    {
        $this->security = $this->createStub(Security::class);
        $projectRepository = $this->createStub(IndustryProjectRepository::class);
        $this->contributionRepository = $this->createStub(IndustryProjectContributionRepository::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->hub = $this->createMock(HubInterface::class);
        $this->unclaimedStockGuard = $this->createMock(UnclaimedStockGuard::class);
        $memberRepository = $this->createStub(IndustryProjectParticipantRepository::class);
        $this->security->method('getUser')->willReturnCallback(fn (): ?User => $this->actingUser);
        $this->contributionRepository->method('find')->willReturnCallback(fn (): ?IndustryProjectContribution => $this->currentContribution);

        $jita = $this->createStub(JitaMarketService::class);
        $jita->method('getCheapestPercentilePrices')->willReturn([34 => 6.0]);
        $service = new ProjectContributionService(
            $this->contributionRepository,
            $this->entityManager,
            $jita,
            $this->createStub(LineReservationService::class),
            $this->createStub(IndustryProjectService::class),
            $this->createStub(ContributorPaidPurchaseReader::class),
        );
        $accessChecker = new ProjectAccessChecker($memberRepository);
        $publisher = new MercurePublisherService($this->hub, new NullLogger());
        $this->editProcessor = new EditContributionProcessor($this->security, $projectRepository, $this->contributionRepository, $service, $accessChecker, new ProjectResourceMapper(), $publisher, $this->unclaimedStockGuard);
        $this->withdrawProcessor = new WithdrawContributionProcessor($this->security, $projectRepository, $this->contributionRepository, $service, $accessChecker, $publisher);

        $this->owner = new User();
        $this->contributorUser = new User();
        $this->project = (new IndustryProject())->setOwner($this->owner);
        $this->project->enrolOwner();
        $this->project->setStatus(ProjectStatus::InProgress);
        $this->assignId($this->project);
        $projectRepository->method('find')->willReturn($this->project);
        $this->contributor = $this->addMember($this->contributorUser, ParticipantRole::Member);
        $this->line = (new IndustryProjectBomLine())->setTypeId(34)->setTypeName('Tritanium')->setRequiredQuantity(1000)->setIsJob(false)->setFulfilledQuantity(0);
        $this->assignId($this->line);
        $this->project->addBomItem($this->line);
    }

    public function testTheContributorEditsTheirPendingContributionAndTheProjectIsNotified(): void
    {
        $contribution = $this->pendingContributionOf($this->contributor);
        $payloads = $this->capturePublishedPayloads();

        $resource = $this->edit($this->contributorUser, $contribution, quantity: self::NEW_QUANTITY, note: 'bought');

        self::assertSame(self::NEW_QUANTITY, $resource->quantity);
        self::assertSame('bought', $resource->note);
        self::assertSame('pending', $resource->status);
        self::assertCount(1, $payloads);
        self::assertSame('contribution_updated', $payloads[0]['action']);
        self::assertSame($contribution->getId()->toRfc4122(), $payloads[0]['data']['contributionId']);
        self::assertSame(self::NEW_QUANTITY, $payloads[0]['data']['quantity']);
    }

    #[Group('issue-1050')]
    public function testEditingAPendingLaunchPriceClaimIsCheckedAgainstTheUnclaimedStockGivingBackItsOwnQuantity(): void
    {
        $this->line->setLaunchPrice(12.5);
        $contribution = $this->pendingContributionOf($this->contributor)->setValueSource(ContributionValueSource::LaunchPrice);
        $this->unclaimedStockGuard->expects(self::once())->method('assertWithin')->with($this->line, self::NEW_QUANTITY, self::PENDING_QUANTITY);

        $resource = $this->edit($this->contributorUser, $contribution, quantity: self::NEW_QUANTITY);

        self::assertSame([self::NEW_QUANTITY, 1_875.0], [$resource->quantity, $resource->estimatedValue]);
    }

    #[Group('issue-1050')]
    public function testEditingAClaimBeyondTheUnclaimedStockIsABadRequestAndKeepsTheQuantity(): void
    {
        $this->line->setLaunchPrice(12.5);
        $contribution = $this->pendingContributionOf($this->contributor)->setValueSource(ContributionValueSource::LaunchPrice);
        $this->unclaimedStockGuard->method('assertWithin')->willThrowException(new \DomainException('The quantity exceeds the unclaimed stock of the line (120).'));

        try {
            $this->edit($this->contributorUser, $contribution, quantity: self::NEW_QUANTITY);
            self::fail('The edit was accepted');
        } catch (BadRequestHttpException) {
            self::assertSame(self::PENDING_QUANTITY, $contribution->getQuantity());
        }
    }

    #[Group('issue-1050')]
    public function testEditingAJitaPricedContributionDoesNotTouchTheUnclaimedStockGuard(): void
    {
        $contribution = $this->pendingContributionOf($this->contributor);
        $this->unclaimedStockGuard->expects(self::never())->method('assertWithin');

        $this->edit($this->contributorUser, $contribution, quantity: self::NEW_QUANTITY);
    }

    public function testTheContributorWithdrawsTheirPendingContributionAndTheProjectIsNotified(): void
    {
        $contribution = $this->pendingContributionOf($this->contributor);
        $payloads = $this->capturePublishedPayloads();
        $this->entityManager->expects(self::once())->method('remove')->with($contribution);

        $this->withdraw($this->contributorUser, $contribution);

        self::assertCount(1, $payloads);
        self::assertSame('contribution_withdrawn', $payloads[0]['action']);
        self::assertSame($contribution->getId()->toRfc4122(), $payloads[0]['data']['contributionId']);
    }

    public function testAnotherParticipantCannotEditOrWithdraw(): void
    {
        $otherUser = new User();
        $this->addMember($otherUser, ParticipantRole::Member);
        $contribution = $this->pendingContributionOf($this->contributor);

        $this->assertBothRefusedFor($otherUser, $contribution, AccessDeniedHttpException::class);
    }

    public function testTheOwnerAndAdminsCannotEditOrWithdrawAParticipantsContribution(): void
    {
        $adminUser = new User();
        $this->addMember($adminUser, ParticipantRole::Admin);
        $contribution = $this->pendingContributionOf($this->contributor);

        $this->assertBothRefusedFor($this->owner, $contribution, AccessDeniedHttpException::class);
        $this->assertBothRefusedFor($adminUser, $contribution, AccessDeniedHttpException::class);
    }

    public function testARemovedMemberCannotEditOrWithdraw(): void
    {
        $contribution = $this->pendingContributionOf($this->contributor);
        $this->contributor->setStatus(ParticipantStatus::Removed);

        $this->assertBothRefusedFor($this->contributorUser, $contribution, AccessDeniedHttpException::class);
    }

    public function testAnApprovedContributionIsRefusedAsABadRequest(): void
    {
        $contribution = $this->pendingContributionOf($this->contributor)->setStatus(ContributionStatus::Approved);

        $this->assertBothRefusedFor($this->contributorUser, $contribution, BadRequestHttpException::class);
    }

    public function testAContributionOfAnotherProjectIsNotFound(): void
    {
        $contribution = $this->pendingContributionOf($this->contributor);
        $contribution->setProject((new IndustryProject())->setOwner(new User()));

        $this->assertBothRefusedFor($this->contributorUser, $contribution, NotFoundHttpException::class);
    }

    public function testTheResourceExposesTheEditRightOnlyToTheOwnerOfAPendingContribution(): void
    {
        $mapper = new ProjectResourceMapper();
        $contribution = $this->pendingContributionOf($this->contributor);

        self::assertTrue($mapper->contributionToResource($contribution, $this->contributorUser)->editableByViewer);
        self::assertFalse($mapper->contributionToResource($contribution, $this->owner)->editableByViewer);
        self::assertFalse($mapper->contributionToResource($contribution)->editableByViewer);
        self::assertFalse($mapper->contributionToResource($contribution->setStatus(ContributionStatus::Approved), $this->contributorUser)->editableByViewer);
    }

    public function testAnAutoDetectedContributionOrACompletedProjectIsNotEditable(): void
    {
        $mapper = new ProjectResourceMapper();
        $contribution = $this->pendingContributionOf($this->contributor);

        self::assertFalse($mapper->contributionToResource($contribution->setIsAutoDetected(true), $this->contributorUser)->editableByViewer);

        $contribution->setIsAutoDetected(false);
        $this->project->setStatus(ProjectStatus::Completed);
        self::assertFalse($mapper->contributionToResource($contribution, $this->contributorUser)->editableByViewer);
    }

    public function testTheContributionApiDeclaresTheDeleteAndTheEditOperations(): void
    {
        $operations = (new \ReflectionClass(\App\ApiResource\Industry\Project\ProjectContributionResource::class))
            ->getAttributes(\ApiPlatform\Metadata\ApiResource::class)[0]->newInstance()->getOperations();
        $byClass = [];
        foreach ($operations as $operation) {
            $byClass[$operation::class][] = $operation;
        }

        self::assertCount(1, $byClass[Delete::class]);
        self::assertSame(WithdrawContributionProcessor::class, $byClass[Delete::class][0]->getProcessor());
        self::assertSame(\App\State\Provider\Industry\Project\ProjectContributionItemProvider::class, $byClass[Delete::class][0]->getProvider());
        $editOperations = array_values(array_filter($byClass[Patch::class], static fn (Patch $patch): bool => EditContributionProcessor::class === $patch->getProcessor()));
        self::assertCount(1, $editOperations);
        $input = $editOperations[0]->getInput();
        self::assertSame(EditContributionInput::class, \is_array($input) ? $input['class'] : $input);
        self::assertArrayHasKey('projectId', $editOperations[0]->getUriVariables());
        self::assertArrayHasKey('id', $editOperations[0]->getUriVariables());
        self::assertArrayHasKey('projectId', $byClass[Delete::class][0]->getUriVariables());
    }

    private function assertBothRefusedFor(User $user, IndustryProjectContribution $contribution, string $exception): void
    {
        $this->entityManager->expects(self::never())->method('remove');
        $this->entityManager->expects(self::never())->method('flush');
        $this->hub->expects(self::never())->method('publish');

        foreach ([
            fn () => $this->edit($user, $contribution, quantity: self::NEW_QUANTITY),
            fn () => $this->withdraw($user, $contribution),
        ] as $action) {
            try {
                $action();
                self::fail('The action was accepted');
            } catch (\Throwable $refusal) {
                self::assertInstanceOf($exception, $refusal);
            }
        }

        self::assertSame(self::PENDING_QUANTITY, $contribution->getQuantity());
    }

    private function edit(User $user, IndustryProjectContribution $contribution, ?int $quantity = null, ?string $note = null): \App\ApiResource\Industry\Project\ProjectContributionResource
    {
        $this->actingUser = $user;
        $this->currentContribution = $contribution;
        $input = new EditContributionInput();
        $input->quantity = $quantity;
        $input->note = $note;

        return $this->editProcessor->process($input, new Patch(), ['projectId' => $this->project->getId()->toRfc4122(), 'id' => $contribution->getId()->toRfc4122()]);
    }

    private function withdraw(User $user, IndustryProjectContribution $contribution): void
    {
        $this->actingUser = $user;
        $this->currentContribution = $contribution;

        $this->withdrawProcessor->process(null, new Delete(), ['projectId' => $this->project->getId()->toRfc4122(), 'id' => $contribution->getId()->toRfc4122()]);
    }

    private function pendingContributionOf(IndustryProjectParticipant $member): IndustryProjectContribution
    {
        $contribution = (new IndustryProjectContribution())
            ->setProject($this->project)
            ->setMember($member)
            ->setBomItem($this->line)
            ->setType(ContributionType::Material)
            ->setQuantity(self::PENDING_QUANTITY)
            ->setEstimatedValue(500.0)
            ->setStatus(ContributionStatus::Pending);
        $this->assignId($contribution);
        $this->project->addContribution($contribution);

        return $contribution;
    }

    private function addMember(User $user, ParticipantRole $role): IndustryProjectParticipant
    {
        $member = (new IndustryProjectParticipant())->setUser($user)->setRole($role)->setStatus(ParticipantStatus::Accepted);
        $this->assignId($member);
        $this->project->addMember($member);

        return $member;
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
