<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\GroupIndustry;

use ApiPlatform\Metadata\Patch;
use ApiPlatform\Validator\Exception\ValidationException;
use App\ApiResource\GroupIndustry\GroupIndustryProjectResource;
use App\ApiResource\Input\GroupIndustry\UpdateGroupProjectInput;
use App\Entity\GroupIndustryProject;
use App\Entity\User;
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
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Uid\Uuid;

#[CoversClass(UpdateGroupProjectProcessor::class)]
#[AllowMockObjectsWithoutExpectations]
class UpdateGroupProjectProcessorTest extends TestCase
{
    private EntityManagerInterface&MockObject $entityManager;
    private UpdateGroupProjectProcessor $processor;
    private GroupIndustryProject $project;

    protected function setUp(): void
    {
        $owner = $this->createStub(User::class);

        $this->project = new GroupIndustryProject();
        $this->project->setOwner($owner);
        $this->project->setName('Orca batch');
        $this->project->setContainerName('GI Orca hangar');
        $this->project->setBrokerFeePercent(3.0);
        $this->project->setSalesTaxPercent(3.6);
        $this->assignId($this->project);

        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($owner);
        $projectRepository = $this->createStub(GroupIndustryProjectRepository::class);
        $projectRepository->method('find')->willReturn($this->project);
        $memberRepository = $this->createStub(GroupIndustryProjectMemberRepository::class);
        $memberRepository->method('findOneBy')->willReturn(null);

        $this->entityManager = $this->createMock(EntityManagerInterface::class);

        $this->processor = new UpdateGroupProjectProcessor(
            $security,
            $projectRepository,
            $memberRepository,
            new GroupProjectAccessChecker($memberRepository),
            new GroupIndustryResourceMapper(),
            $this->entityManager,
            new MercurePublisherService($this->createStub(HubInterface::class), new NullLogger()),
        );
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
