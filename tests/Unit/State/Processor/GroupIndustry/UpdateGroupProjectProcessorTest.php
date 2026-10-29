<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\GroupIndustry;

use ApiPlatform\Metadata\Patch;
use ApiPlatform\Validator\Exception\ValidationException;
use App\ApiResource\GroupIndustry\GroupIndustryProjectResource;
use App\ApiResource\Input\GroupIndustry\UpdateGroupProjectInput;
use App\Entity\GroupIndustryProject;
use App\Entity\GroupIndustryProjectMember;
use App\Entity\User;
use App\Enum\GroupMemberRole;
use App\Enum\GroupMemberStatus;
use App\Enum\GroupProjectStatus;
use App\Repository\GroupIndustryProjectMemberRepository;
use App\Repository\GroupIndustryProjectRepository;
use App\Service\Mercure\MercurePublisherService;
use App\State\Processor\GroupIndustry\UpdateGroupProjectProcessor;
use App\State\Provider\GroupIndustry\GroupIndustryResourceMapper;
use App\State\Provider\GroupIndustry\GroupProjectAccessChecker;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
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

#[CoversClass(UpdateGroupProjectProcessor::class)]
#[AllowMockObjectsWithoutExpectations]
class UpdateGroupProjectProcessorTest extends TestCase
{
    private EntityManagerInterface&MockObject $entityManager;
    private UpdateGroupProjectProcessor $processor;
    private HubInterface&MockObject $hub;
    private GroupIndustryProject $project;
    private User $owner;

    protected function setUp(): void
    {
        $this->owner = $this->createStub(User::class);

        $this->project = new GroupIndustryProject();
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
        $this->project->setStatus(GroupProjectStatus::Completed);
        $input = new UpdateGroupProjectInput();
        $input->salesTaxPercent = 2.0;

        $this->assertUpdateIsRefused($input);

        self::assertSame(3.6, $this->project->getSalesTaxPercent());
    }

    public function testOwnerCannotChangeTheBrokerFeeOfACompletedProject(): void
    {
        $this->project->setStatus(GroupProjectStatus::Completed);
        $input = new UpdateGroupProjectInput();
        $input->brokerFeePercent = 1.5;

        $this->assertUpdateIsRefused($input);

        self::assertSame(3.0, $this->project->getBrokerFeePercent());
    }

    public function testOwnerCanRenameACompletedProject(): void
    {
        $this->project->setStatus(GroupProjectStatus::Completed);
        $input = new UpdateGroupProjectInput();
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
        $this->project->setStatus(GroupProjectStatus::Selling);
        $input = new UpdateGroupProjectInput();
        $input->salesTaxPercent = 2.0;

        $this->entityManager->expects($this->once())->method('flush');

        $resource = $this->update($input);

        self::assertSame(2.0, $resource->salesTaxPercent);
        self::assertSame(2.0, $this->project->getSalesTaxPercent());
        self::assertSame('selling', $resource->status);
    }

    public function testOwnerCanChangeTheSalesTaxOfASellingProjectInTheSameUpdateThatCompletesIt(): void
    {
        $this->project->setStatus(GroupProjectStatus::Selling);
        $input = new UpdateGroupProjectInput();
        $input->status = 'completed';
        $input->salesTaxPercent = 2.0;

        $this->entityManager->expects($this->once())->method('flush');

        $resource = $this->update($input);

        self::assertSame(2.0, $resource->salesTaxPercent);
        self::assertSame(2.0, $this->project->getSalesTaxPercent());
        self::assertSame('completed', $resource->status);
        self::assertSame(GroupProjectStatus::Completed, $this->project->getStatus());
        self::assertNotNull($this->project->getCompletedAt());
    }

    #[Group('issue-257')]
    public function testSalesTaxBringingTheFeesToExactlyOneHundredWithTheRecordedBrokerFeeIsSaved(): void
    {
        $this->project->setBrokerFeePercent(3.6);
        $input = new UpdateGroupProjectInput();
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
        $input = new UpdateGroupProjectInput();
        $input->name = 'Rorqual batch';
        $input->salesTaxPercent = 96.5;

        $this->assertFeesAreRejectedOnTheSalesTax($input);

        self::assertSame(['Orca batch', 3.6, 3.6], [$this->project->getName(), $this->project->getBrokerFeePercent(), $this->project->getSalesTaxPercent()]);
    }

    #[Group('issue-257')]
    public function testBrokerFeeBringingTheFeesOverOneHundredWithTheRecordedSalesTaxIsRejectedOnTheSalesTaxAndChangesNothing(): void
    {
        $input = new UpdateGroupProjectInput();
        $input->brokerFeePercent = 99.0;

        $this->assertFeesAreRejectedOnTheSalesTax($input);

        self::assertSame([3.0, 3.6], [$this->project->getBrokerFeePercent(), $this->project->getSalesTaxPercent()]);
    }

    #[Group('issue-257')]
    public function testBrokerFeeBringingTheFeesToExactlyOneHundredWithTheRecordedSalesTaxIsSaved(): void
    {
        $input = new UpdateGroupProjectInput();
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
        $this->processor = $this->processorActingAs($member, $this->membershipOf($member, GroupMemberRole::Member));
        $input = new UpdateGroupProjectInput();
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
        $input = new UpdateGroupProjectInput();
        $input->name = 'Rorqual batch';
        $this->entityManager->expects($this->never())->method('flush');

        $this->expectException(AccessDeniedHttpException::class);

        $this->update($input);
    }

    #[Group('issue-168')]
    public function testAdminCanUpdateTheProjectAndSeesHisOwnMembershipInTheResource(): void
    {
        $admin = $this->createStub(User::class);
        $this->processor = $this->processorActingAs($admin, $this->membershipOf($admin, GroupMemberRole::Admin));
        $input = new UpdateGroupProjectInput();
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
        $input = new UpdateGroupProjectInput();
        $input->salesTaxPercent = 2.0;
        $before = new \DateTimeImmutable();

        $this->update($input);

        self::assertNotNull($this->project->getUpdatedAt());
        self::assertGreaterThanOrEqual($before, $this->project->getUpdatedAt());
    }

    #[Group('issue-168')]
    public function testNameOfTheUpdateIsTrimmedAndABlankNameClearsIt(): void
    {
        $input = new UpdateGroupProjectInput();
        $input->name = '  Rorqual batch  ';
        $this->update($input);
        self::assertSame('Rorqual batch', $this->project->getName());

        $blank = new UpdateGroupProjectInput();
        $blank->name = '   ';
        $this->update($blank);
        self::assertNull($this->project->getName());
    }

    #[Group('issue-168')]
    public function testUpdateWithoutContainerNameKeepsTheContainerNameOfTheProject(): void
    {
        $input = new UpdateGroupProjectInput();
        $input->name = 'Rorqual batch';

        $this->update($input);

        self::assertSame('GI Orca hangar', $this->project->getContainerName());
    }

    #[Group('issue-168')]
    public function testUpdateWithAContainerNameReplacesItAndAnEmptyOneClearsIt(): void
    {
        $input = new UpdateGroupProjectInput();
        $input->containerName = 'GI Rorqual hangar';
        $this->update($input);
        self::assertSame('GI Rorqual hangar', $this->project->getContainerName());

        $empty = new UpdateGroupProjectInput();
        $empty->containerName = '';
        $this->update($empty);
        self::assertNull($this->project->getContainerName());
    }

    #[Group('issue-168')]
    public function testStatusChangeIsPublishedWithTheOldAndTheNewStatus(): void
    {
        $this->project->setStatus(GroupProjectStatus::Published);
        $input = new UpdateGroupProjectInput();
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
        $this->project->setStatus(GroupProjectStatus::Selling);
        $input = new UpdateGroupProjectInput();
        $input->salesTaxPercent = 2.0;
        $this->hub->expects($this->never())->method('publish');

        $this->update($input);
    }

    #[Group('issue-168')]
    public function testStatusCannotSkipAStepAndNothingIsChangedNorPublished(): void
    {
        $this->project->setStatus(GroupProjectStatus::Published);
        $input = new UpdateGroupProjectInput();
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
        self::assertSame(GroupProjectStatus::Published, $this->project->getStatus());
    }

    private function assertFeesAreRejectedOnTheSalesTax(UpdateGroupProjectInput $input): void
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

    private function assertUpdateIsRefused(UpdateGroupProjectInput $input): void
    {
        $this->entityManager->expects($this->never())->method('flush');

        $refusal = null;
        try {
            $this->update($input);
        } catch (BadRequestHttpException $e) {
            $refusal = $e;
        }

        self::assertInstanceOf(BadRequestHttpException::class, $refusal);
        self::assertSame(GroupProjectStatus::Completed, $this->project->getStatus());
    }

    private function processorActingAs(User $actor, ?GroupIndustryProjectMember $membership): UpdateGroupProjectProcessor
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($actor);
        $projectRepository = $this->createStub(GroupIndustryProjectRepository::class);
        $projectRepository->method('find')->willReturn($this->project);
        $memberRepository = $this->createStub(GroupIndustryProjectMemberRepository::class);
        $memberRepository->method('findOneBy')->willReturnCallback(
            fn (array $criteria): ?GroupIndustryProjectMember => $membership !== null
                && ($criteria['project'] ?? null) === $this->project
                && ($criteria['user'] ?? null) === $actor
                && ($criteria['status'] ?? $membership->getStatus()) === $membership->getStatus()
                && ($criteria['role'] ?? $membership->getRole()) === $membership->getRole()
                ? $membership
                : null,
        );

        return new UpdateGroupProjectProcessor(
            $security,
            $projectRepository,
            $memberRepository,
            new GroupProjectAccessChecker($memberRepository),
            new GroupIndustryResourceMapper(),
            $this->entityManager,
            new MercurePublisherService($this->hub, new NullLogger()),
        );
    }

    private function membershipOf(User $user, GroupMemberRole $role, GroupMemberStatus $status = GroupMemberStatus::Accepted): GroupIndustryProjectMember
    {
        $membership = new GroupIndustryProjectMember();
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

    private function update(UpdateGroupProjectInput $input): GroupIndustryProjectResource
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
