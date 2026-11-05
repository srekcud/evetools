<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\Industry\Project;

use ApiPlatform\Metadata\Patch;
use ApiPlatform\Validator\Exception\ValidationException;
use App\ApiResource\Industry\Project\IndustryProjectResource;
use App\ApiResource\Input\Industry\Project\UpdateProjectInput;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectParticipant;
use App\Entity\User;
use App\Enum\ParticipantRole;
use App\Enum\ParticipantStatus;
use App\Enum\ProjectStatus;
use App\Repository\IndustryProjectParticipantRepository;
use App\Repository\IndustryProjectRepository;
use App\Service\Mercure\MercurePublisherService;
use App\State\Processor\Industry\Project\UpdateProjectProcessor;
use App\State\Provider\Industry\Project\ProjectResourceMapper;
use App\State\Provider\Industry\Project\ProjectAccessChecker;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Uid\Uuid;

#[CoversClass(UpdateProjectProcessor::class)]
#[AllowMockObjectsWithoutExpectations]
class UpdateProjectProcessorTest extends TestCase
{
    private EntityManagerInterface&MockObject $entityManager;
    private UpdateProjectProcessor $processor;
    private HubInterface&MockObject $hub;
    private IndustryProject $project;
    private User $owner;

    protected function setUp(): void
    {
        $this->owner = $this->createStub(User::class);

        $this->project = (new IndustryProject());
        $this->project->setOwner($this->owner);
        $this->project->setName('Orca batch');
        $this->project->setContainerName('GI Orca hangar');
        $this->project->setBrokerFeePercent(3.0);
        $this->project->setSalesTaxPercent(3.6);
        $this->assignId($this->project);

        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->hub = $this->createMock(HubInterface::class);

        $this->processor = $this->processorActingAs($this->owner, null);
    }

    public function testOwnerCannotChangeTheSalesTaxOfACompletedProject(): void
    {
        $this->project->setStatus(ProjectStatus::Completed);
        $input = new UpdateProjectInput();
        $input->salesTaxPercent = 2.0;

        $this->assertUpdateIsRefused($input);

        self::assertSame(3.6, $this->project->getSalesTaxPercent());
    }

    public function testOwnerCannotChangeTheBrokerFeeOfACompletedProject(): void
    {
        $this->project->setStatus(ProjectStatus::Completed);
        $input = new UpdateProjectInput();
        $input->brokerFeePercent = 1.5;

        $this->assertUpdateIsRefused($input);

        self::assertSame(3.0, $this->project->getBrokerFeePercent());
    }

    public function testOwnerCanRenameACompletedProject(): void
    {
        $this->project->setStatus(ProjectStatus::Completed);
        $input = new UpdateProjectInput();
        $input->name = 'Rorqual batch';

        $this->entityManager->expects($this->once())->method('flush');

        $resource = $this->update($input);

        self::assertSame('Rorqual batch', $resource->name);
        self::assertSame('Rorqual batch', $this->project->getName());
        self::assertSame('completed', $resource->status);
        self::assertSame(3.0, $resource->brokerFeePercent);
        self::assertSame(3.6, $resource->salesTaxPercent);
    }

    public function testOwnerCanChangeTheSalesTaxOfASellingProject(): void
    {
        $this->project->setStatus(ProjectStatus::Selling);
        $input = new UpdateProjectInput();
        $input->salesTaxPercent = 2.0;

        $this->entityManager->expects($this->once())->method('flush');

        $resource = $this->update($input);

        self::assertSame(2.0, $resource->salesTaxPercent);
        self::assertSame(2.0, $this->project->getSalesTaxPercent());
        self::assertSame('selling', $resource->status);
    }

    public function testOwnerCanChangeTheSalesTaxOfASellingProjectInTheSameUpdateThatCompletesIt(): void
    {
        $this->project->setStatus(ProjectStatus::Selling);
        $input = new UpdateProjectInput();
        $input->status = 'completed';
        $input->salesTaxPercent = 2.0;

        $this->entityManager->expects($this->once())->method('flush');

        $resource = $this->update($input);

        self::assertSame(2.0, $resource->salesTaxPercent);
        self::assertSame(2.0, $this->project->getSalesTaxPercent());
        self::assertSame('completed', $resource->status);
        self::assertSame(ProjectStatus::Completed, $this->project->getStatus());
        self::assertNotNull($this->project->getCompletedAt());
    }

    #[Group('issue-347')]
    public function testSalesTaxAboveTheMaximumIsSavedWhenTheRecordedBrokerFeeIsUnknown(): void
    {
        $this->project->setBrokerFeePercent(null);
        $input = new UpdateProjectInput();
        $input->salesTaxPercent = 99.0;

        $resource = $this->update($input);

        self::assertSame([null, 99.0], [$this->project->getBrokerFeePercent(), $this->project->getSalesTaxPercent()]);
        self::assertSame([null, 99.0], [$resource->brokerFeePercent, $resource->salesTaxPercent]);
    }

    #[Group('issue-257')]
    public function testSalesTaxBringingTheFeesToExactlyOneHundredWithTheRecordedBrokerFeeIsSaved(): void
    {
        $this->project->setBrokerFeePercent(3.6);
        $input = new UpdateProjectInput();
        $input->salesTaxPercent = 96.4;

        $this->entityManager->expects($this->once())->method('flush');

        $resource = $this->update($input);

        self::assertSame([3.6, 96.4], [$this->project->getBrokerFeePercent(), $this->project->getSalesTaxPercent()]);
        self::assertSame(96.4, $resource->salesTaxPercent);
    }

    #[Group('issue-257')]
    public function testSalesTaxBringingTheFeesOverOneHundredWithTheRecordedBrokerFeeIsRejectedOnTheSalesTaxAndChangesNothing(): void
    {
        $this->project->setBrokerFeePercent(3.6);
        $input = new UpdateProjectInput();
        $input->name = 'Rorqual batch';
        $input->salesTaxPercent = 96.5;

        $this->assertFeesAreRejectedOnTheSalesTax($input);

        self::assertSame(['Orca batch', 3.6, 3.6], [$this->project->getName(), $this->project->getBrokerFeePercent(), $this->project->getSalesTaxPercent()]);
    }

    #[Group('issue-257')]
    public function testBrokerFeeBringingTheFeesOverOneHundredWithTheRecordedSalesTaxIsRejectedOnTheSalesTaxAndChangesNothing(): void
    {
        $input = new UpdateProjectInput();
        $input->brokerFeePercent = 99.0;

        $this->assertFeesAreRejectedOnTheSalesTax($input);

        self::assertSame([3.0, 3.6], [$this->project->getBrokerFeePercent(), $this->project->getSalesTaxPercent()]);
    }

    #[Group('issue-257')]
    public function testBrokerFeeBringingTheFeesToExactlyOneHundredWithTheRecordedSalesTaxIsSaved(): void
    {
        $input = new UpdateProjectInput();
        $input->brokerFeePercent = 96.4;

        $this->entityManager->expects($this->once())->method('flush');

        $resource = $this->update($input);

        self::assertSame([96.4, 3.6], [$this->project->getBrokerFeePercent(), $this->project->getSalesTaxPercent()]);
        self::assertSame(96.4, $resource->brokerFeePercent);
    }

    #[Group('issue-168')]
    public function testMemberWhoIsNotAnAdminCannotUpdateTheProjectAndNothingIsChanged(): void
    {
        $member = $this->createStub(User::class);
        $this->processor = $this->processorActingAs($member, $this->membershipOf($member, ParticipantRole::Member));
        $input = new UpdateProjectInput();
        $input->name = 'Rorqual batch';
        $input->salesTaxPercent = 2.0;
        $this->entityManager->expects($this->never())->method('flush');
        $this->hub->expects($this->never())->method('publish');

        $refusal = null;
        try {
            $this->update($input);
        } catch (AccessDeniedHttpException $exception) {
            $refusal = $exception;
        }

        self::assertInstanceOf(AccessDeniedHttpException::class, $refusal);
        self::assertSame('Only project owner or admin can perform this action', $refusal->getMessage());
        self::assertSame('Orca batch', $this->project->getName());
        self::assertSame(3.6, $this->project->getSalesTaxPercent());
        self::assertNull($this->project->getUpdatedAt());
    }

    #[Group('issue-168')]
    public function testUserWithoutMembershipCannotUpdateTheProject(): void
    {
        $this->processor = $this->processorActingAs($this->createStub(User::class), null);
        $input = new UpdateProjectInput();
        $input->name = 'Rorqual batch';
        $this->entityManager->expects($this->never())->method('flush');

        $this->expectException(AccessDeniedHttpException::class);

        $this->update($input);
    }

    #[Group('issue-168')]
    public function testAdminCanUpdateTheProjectAndSeesHisOwnMembershipInTheResource(): void
    {
        $admin = $this->createStub(User::class);
        $this->processor = $this->processorActingAs($admin, $this->membershipOf($admin, ParticipantRole::Admin));
        $input = new UpdateProjectInput();
        $input->name = 'Rorqual batch';
        $this->entityManager->expects($this->once())->method('flush');

        $resource = $this->update($input);

        self::assertSame('Rorqual batch', $this->project->getName());
        self::assertSame('admin', $resource->myRole);
        self::assertSame('accepted', $resource->myStatus);
    }

    #[Group('issue-168')]
    public function testUpdateStampsTheUpdateDateOfTheProject(): void
    {
        $input = new UpdateProjectInput();
        $input->salesTaxPercent = 2.0;
        $before = new \DateTimeImmutable();

        $this->update($input);

        self::assertNotNull($this->project->getUpdatedAt());
        self::assertGreaterThanOrEqual($before, $this->project->getUpdatedAt());
    }

    #[Group('issue-168')]
    public function testNameOfTheUpdateIsTrimmedAndABlankNameClearsIt(): void
    {
        $input = new UpdateProjectInput();
        $input->name = '  Rorqual batch  ';
        $this->update($input);
        self::assertSame('Rorqual batch', $this->project->getName());

        $blank = new UpdateProjectInput();
        $blank->name = '   ';
        $this->update($blank);
        self::assertNull($this->project->getName());
    }

    #[Group('issue-168')]
    public function testUpdateWithoutContainerNameKeepsTheContainerNameOfTheProject(): void
    {
        $input = new UpdateProjectInput();
        $input->name = 'Rorqual batch';

        $this->update($input);

        self::assertSame('GI Orca hangar', $this->project->getContainerName());
    }

    #[Group('issue-168')]
    public function testUpdateWithAContainerNameReplacesItAndAnEmptyOneClearsIt(): void
    {
        $input = new UpdateProjectInput();
        $input->containerName = 'GI Rorqual hangar';
        $this->update($input);
        self::assertSame('GI Rorqual hangar', $this->project->getContainerName());

        $empty = new UpdateProjectInput();
        $empty->containerName = '';
        $this->update($empty);
        self::assertNull($this->project->getContainerName());
    }

    #[Group('issue-168')]
    public function testStatusChangeIsPublishedWithTheOldAndTheNewStatus(): void
    {
        $this->project->setStatus(ProjectStatus::Published);
        $input = new UpdateProjectInput();
        $input->status = 'in_progress';
        $payloads = $this->capturePublishedPayloads();

        $this->update($input);

        self::assertCount(1, $payloads);
        self::assertSame('status_changed', $payloads[0]['action']);
        self::assertSame($this->project->getId()->toRfc4122(), $payloads[0]['projectId']);
        self::assertSame(['oldStatus' => 'published', 'newStatus' => 'in_progress'], $payloads[0]['data']);
    }

    #[Group('issue-168')]
    public function testUpdateThatDoesNotChangeTheStatusPublishesNothing(): void
    {
        $this->project->setStatus(ProjectStatus::Selling);
        $input = new UpdateProjectInput();
        $input->salesTaxPercent = 2.0;
        $this->hub->expects($this->never())->method('publish');

        $this->update($input);
    }

    #[Group('issue-168')]
    public function testStatusCannotSkipAStepAndNothingIsChangedNorPublished(): void
    {
        $this->project->setStatus(ProjectStatus::Published);
        $input = new UpdateProjectInput();
        $input->status = 'selling';
        $this->entityManager->expects($this->never())->method('flush');
        $this->hub->expects($this->never())->method('publish');

        $refusal = null;
        try {
            $this->update($input);
        } catch (BadRequestHttpException $exception) {
            $refusal = $exception;
        }

        self::assertInstanceOf(BadRequestHttpException::class, $refusal);
        self::assertSame('Cannot transition from "published" to "selling"', $refusal->getMessage());
        self::assertSame(ProjectStatus::Published, $this->project->getStatus());
    }

    #[Group('issue-362')]
    public function testDraftProjectCanBePublishedAndTheChangeIsPublishedWithTheOldAndTheNewStatus(): void
    {
        $this->project->setStatus(ProjectStatus::Draft);
        $input = new UpdateProjectInput();
        $input->status = 'published';
        $payloads = $this->capturePublishedPayloads();

        $resource = $this->update($input);

        self::assertSame('published', $resource->status);
        self::assertSame(ProjectStatus::Published, $this->project->getStatus());
        self::assertCount(1, $payloads);
        self::assertSame(['oldStatus' => 'draft', 'newStatus' => 'published'], $payloads[0]['data']);
    }

    #[Group('issue-362')]
    public function testDraftProjectCanStartDirectlyInProgress(): void
    {
        $this->project->setStatus(ProjectStatus::Draft);
        $input = new UpdateProjectInput();
        $input->status = 'in_progress';

        $resource = $this->update($input);

        self::assertSame('in_progress', $resource->status);
        self::assertSame(ProjectStatus::InProgress, $this->project->getStatus());
        self::assertNull($this->project->getCompletedAt());
    }

    #[Group('issue-362')]
    public function testDraftProjectCannotJumpToSellingAndNothingIsChangedNorPublished(): void
    {
        $this->project->setStatus(ProjectStatus::Draft);
        $input = new UpdateProjectInput();
        $input->status = 'selling';
        $this->entityManager->expects($this->never())->method('flush');
        $this->hub->expects($this->never())->method('publish');

        $refusal = null;
        try {
            $this->update($input);
        } catch (BadRequestHttpException $exception) {
            $refusal = $exception;
        }

        self::assertInstanceOf(BadRequestHttpException::class, $refusal);
        self::assertSame('Cannot transition from "draft" to "selling"', $refusal->getMessage());
        self::assertSame(ProjectStatus::Draft, $this->project->getStatus());
    }

    #[Group('issue-362')]
    public function testSellingProjectCompletesWithTheCompletionDateAndCompletedProjectCannotBeReopened(): void
    {
        $this->project->setStatus(ProjectStatus::Selling);
        $completion = new UpdateProjectInput();
        $completion->status = 'completed';

        $this->update($completion);

        self::assertSame(ProjectStatus::Completed, $this->project->getStatus());
        self::assertNotNull($this->project->getCompletedAt());

        $reopening = new UpdateProjectInput();
        $reopening->status = 'in_progress';
        $refusal = null;
        try {
            $this->update($reopening);
        } catch (BadRequestHttpException $exception) {
            $refusal = $exception;
        }

        self::assertInstanceOf(BadRequestHttpException::class, $refusal);
        self::assertSame('Cannot transition from "completed" to "in_progress"', $refusal->getMessage());
    }

    public static function everyProjectStatus(): iterable
    {
        foreach (ProjectStatus::cases() as $status) {
            yield $status->value => [$status];
        }
    }

    #[Group('issue-362')]
    #[DataProvider('everyProjectStatus')]
    public function testPrivateFlagIsModifiableInEveryStatusAndNothingElseChanges(ProjectStatus $status): void
    {
        $this->project->setStatus($status)->setPrivate(true);
        $input = new UpdateProjectInput();
        $input->private = false;
        $payloads = $this->capturePublishedPayloads();

        $resource = $this->update($input);

        self::assertFalse($resource->private);
        self::assertFalse($this->project->isPrivate());
        self::assertSame($status, $this->project->getStatus());
        self::assertSame('Orca batch', $this->project->getName());
        self::assertCount(0, $payloads);
    }

    #[Group('issue-362')]
    public function testPrivateFlagCanBeSetBackToTrueAndAnUpdateWithoutItKeepsIt(): void
    {
        $this->project->setPrivate(false);
        $setBack = new UpdateProjectInput();
        $setBack->private = true;

        $this->update($setBack);

        self::assertTrue($this->project->isPrivate());

        $this->project->setPrivate(false);
        $rename = new UpdateProjectInput();
        $rename->name = 'Orca batch two';

        $this->update($rename);

        self::assertFalse($this->project->isPrivate());
    }

    #[Group('issue-362')]
    public function testNotesAreUpdatedAndExposedInTheResource(): void
    {
        $input = new UpdateProjectInput();
        $input->notes = 'Hangar 3, bring the fuel blocks';

        $resource = $this->update($input);

        self::assertSame('Hangar 3, bring the fuel blocks', $this->project->getNotes());
        self::assertSame('Hangar 3, bring the fuel blocks', $resource->notes);
    }

    private function assertFeesAreRejectedOnTheSalesTax(UpdateProjectInput $input): void
    {
        $this->entityManager->expects($this->never())->method('flush');

        $refusal = null;
        try {
            $this->update($input);
        } catch (ValidationException $exception) {
            $refusal = $exception;
        }

        self::assertInstanceOf(ValidationException::class, $refusal);
        self::assertSame(422, $refusal->getStatusCode());
        self::assertCount(1, $refusal->getConstraintViolationList());
        self::assertSame('salesTaxPercent', $refusal->getConstraintViolationList()->get(0)->getPropertyPath());
    }

    private function assertUpdateIsRefused(UpdateProjectInput $input): void
    {
        $this->entityManager->expects($this->never())->method('flush');

        $refusal = null;
        try {
            $this->update($input);
        } catch (BadRequestHttpException $e) {
            $refusal = $e;
        }

        self::assertInstanceOf(BadRequestHttpException::class, $refusal);
        self::assertSame(ProjectStatus::Completed, $this->project->getStatus());
    }

    private function processorActingAs(User $actor, ?IndustryProjectParticipant $membership): UpdateProjectProcessor
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($actor);
        $projectRepository = $this->createStub(IndustryProjectRepository::class);
        $projectRepository->method('find')->willReturn($this->project);
        $memberRepository = $this->createStub(IndustryProjectParticipantRepository::class);
        $memberRepository->method('findOneBy')->willReturnCallback(
            fn (array $criteria): ?IndustryProjectParticipant => $membership !== null
                && ($criteria['project'] ?? null) === $this->project
                && ($criteria['user'] ?? null) === $actor
                && ($criteria['status'] ?? $membership->getStatus()) === $membership->getStatus()
                && ($criteria['role'] ?? $membership->getRole()) === $membership->getRole()
                ? $membership
                : null,
        );

        return new UpdateProjectProcessor(
            $security,
            $projectRepository,
            $memberRepository,
            new ProjectAccessChecker($memberRepository),
            new ProjectResourceMapper(),
            $this->entityManager,
            new MercurePublisherService($this->hub, new NullLogger()),
        );
    }

    private function membershipOf(User $user, ParticipantRole $role, ParticipantStatus $status = ParticipantStatus::Accepted): IndustryProjectParticipant
    {
        $membership = new IndustryProjectParticipant();
        $membership->setProject($this->project);
        $membership->setUser($user);
        $membership->setRole($role);
        $membership->setStatus($status);

        return $membership;
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

    private function update(UpdateProjectInput $input): IndustryProjectResource
    {
        return $this->processor->process($input, new Patch(), [
            'id' => $this->project->getId()->toRfc4122(),
        ]);
    }

    private function assignId(object $entity): void
    {
        $property = new \ReflectionProperty($entity, 'id');
        $property->setValue($entity, Uuid::v4());
    }
}
