<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\GroupIndustry;

use ApiPlatform\Metadata\Post;
use App\ApiResource\GroupIndustry\GroupIndustryContributionResource;
use App\ApiResource\Input\GroupIndustry\SubmitContributionInput;
use App\Entity\Character;
use App\Entity\GroupIndustryBomItem;
use App\Entity\GroupIndustryContribution;
use App\Entity\GroupIndustryProject;
use App\Entity\GroupIndustryProjectMember;
use App\Entity\User;
use App\Enum\GroupMemberRole;
use App\Enum\GroupMemberStatus;
use App\Enum\GroupProjectStatus;
use App\Repository\GroupIndustryBomItemRepository;
use App\Repository\GroupIndustryContributionRepository;
use App\Repository\GroupIndustryProjectMemberRepository;
use App\Repository\GroupIndustryProjectRepository;
use App\Service\GroupIndustry\GroupIndustryContributionService;
use App\Service\Mercure\MercurePublisherService;
use App\State\Processor\GroupIndustry\SubmitContributionProcessor;
use App\State\Provider\GroupIndustry\GroupIndustryResourceMapper;
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
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Uid\Uuid;

#[CoversClass(SubmitContributionProcessor::class)]
#[AllowMockObjectsWithoutExpectations]
class SubmitContributionProcessorTest extends TestCase
{
    private const int MORPHITE_TYPE_ID = 11399;
    private const int TRITANIUM_TYPE_ID = 34;
    private const int ISOGEN_TYPE_ID = 37;
    private const float TRITANIUM_BOM_PRICE = 5.5;

    private EntityManagerInterface&MockObject $entityManager;
    private HubInterface&MockObject $hub;
    private SubmitContributionProcessor $processor;
    private GroupIndustryProject $project;
    private GroupIndustryProject $otherProject;
    private GroupIndustryBomItem $morphite;
    private GroupIndustryBomItem $tritanium;
    private GroupIndustryBomItem $isogenOfTheOtherProject;
    private User $contributor;

    protected function setUp(): void
    {
        $contributor = $this->createStub(User::class);
        $this->contributor = $contributor;

        $this->project = new GroupIndustryProject();
        $this->project->setOwner($this->createStub(User::class));
        $this->assignId($this->project);

        $this->otherProject = new GroupIndustryProject();
        $this->otherProject->setOwner($this->createStub(User::class));
        $this->assignId($this->otherProject);

        $member = new GroupIndustryProjectMember();
        $member->setUser($contributor);
        $member->setRole(GroupMemberRole::Member);
        $member->setStatus(GroupMemberStatus::Accepted);
        $member->setProject($this->project);
        $this->assignId($member);

        $this->morphite = new GroupIndustryBomItem();
        $this->morphite->setProject($this->project);
        $this->morphite->setTypeId(self::MORPHITE_TYPE_ID);
        $this->morphite->setTypeName('Morphite');
        $this->morphite->setRequiredQuantity(500);
        $this->assignId($this->morphite);

        $this->tritanium = new GroupIndustryBomItem();
        $this->tritanium->setProject($this->project);
        $this->tritanium->setTypeId(self::TRITANIUM_TYPE_ID);
        $this->tritanium->setTypeName('Tritanium');
        $this->tritanium->setRequiredQuantity(10000);
        $this->tritanium->setEstimatedPrice(self::TRITANIUM_BOM_PRICE);
        $this->assignId($this->tritanium);

        $this->isogenOfTheOtherProject = new GroupIndustryBomItem();
        $this->isogenOfTheOtherProject->setProject($this->otherProject);
        $this->isogenOfTheOtherProject->setTypeId(self::ISOGEN_TYPE_ID);
        $this->isogenOfTheOtherProject->setTypeName('Isogen');
        $this->isogenOfTheOtherProject->setRequiredQuantity(300);
        $this->assignId($this->isogenOfTheOtherProject);

        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($contributor);
        $projectRepository = $this->createStub(GroupIndustryProjectRepository::class);
        $projectRepository->method('find')->willReturnCallback(
            fn (Uuid $projectId): ?GroupIndustryProject => match (true) {
                $projectId->equals($this->project->getId()) => $this->project,
                $projectId->equals($this->otherProject->getId()) => $this->otherProject,
                default => null,
            },
        );
        $memberRepository = $this->createStub(GroupIndustryProjectMemberRepository::class);
        $memberRepository->method('findOneBy')->willReturnCallback(
            fn (array $criteria): ?GroupIndustryProjectMember => ($criteria['project'] ?? null) === $this->project
                && ($criteria['user'] ?? null) === $contributor
                && ($criteria['status'] ?? null) === GroupMemberStatus::Accepted
                ? $member
                : null,
        );
        $bomItemRepository = $this->createStub(GroupIndustryBomItemRepository::class);
        $bomItemRepository->method('find')->willReturnCallback(
            fn (Uuid $bomItemId): ?GroupIndustryBomItem => match (true) {
                $bomItemId->equals($this->morphite->getId()) => $this->morphite,
                $bomItemId->equals($this->tritanium->getId()) => $this->tritanium,
                $bomItemId->equals($this->isogenOfTheOtherProject->getId()) => $this->isogenOfTheOtherProject,
                default => null,
            },
        );
        $contributionRepository = $this->createStub(GroupIndustryContributionRepository::class);
        $contributionRepository->method('findByMemberBomItemAndType')->willReturn(null);

        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->hub = $this->createMock(HubInterface::class);

        $this->processor = new SubmitContributionProcessor(
            $security,
            $projectRepository,
            $memberRepository,
            $bomItemRepository,
            new GroupIndustryContributionService($contributionRepository, $this->entityManager),
            new GroupIndustryResourceMapper(),
            new MercurePublisherService($this->hub, new NullLogger()),
        );
    }

    public function testMaterialContributionWithoutBomPriceNorEnteredValueIsABadRequestAndNothingIsPersisted(): void
    {
        $this->entityManager->expects($this->never())->method('persist');
        $this->entityManager->expects($this->never())->method('flush');
        $this->hub->expects($this->never())->method('publish');

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('No market price for type 11399: enter an estimated value.');

        $this->submitMorphite(100, null);
    }

    public function testMaterialContributionWithoutBomPriceIsRecordedAtTheEnteredValue(): void
    {
        $this->entityManager->expects($this->once())->method('persist')->willReturnCallback(
            fn (GroupIndustryContribution $contribution) => $this->assignId($contribution),
        );
        $this->entityManager->expects($this->once())->method('flush');

        $resource = $this->submitMorphite(40, 900.0);

        self::assertSame('material', $resource->type);
        self::assertSame(40, $resource->quantity);
        self::assertSame(900.0, $resource->estimatedValue);
        self::assertSame('pending', $resource->status);
    }

    public function testMaterialContributionOnACompletedProjectIsABadRequestAndNoContributionIsCreated(): void
    {
        $this->project->setStatus(GroupProjectStatus::Completed);

        $this->entityManager->expects($this->never())->method('persist');
        $this->entityManager->expects($this->never())->method('flush');
        $this->hub->expects($this->never())->method('publish');

        $this->expectException(BadRequestHttpException::class);

        $this->submitMorphite(10, 1250.0);
    }

    public function testMaterialContributionOnASellingProjectIsRecordedAsPending(): void
    {
        $this->project->setStatus(GroupProjectStatus::Selling);

        $this->entityManager->expects($this->once())->method('persist')->willReturnCallback(
            fn (GroupIndustryContribution $contribution) => $this->assignId($contribution),
        );
        $this->entityManager->expects($this->once())->method('flush');

        $resource = $this->submitMorphite(25, 980.0);

        self::assertSame('material', $resource->type);
        self::assertSame(25, $resource->quantity);
        self::assertSame(980.0, $resource->estimatedValue);
        self::assertSame('pending', $resource->status);
    }

    public function testJobInstallContributionWithoutEnteredValueIsABadRequestAndNoContributionIsCreated(): void
    {
        $this->entityManager->expects($this->never())->method('persist');
        $this->entityManager->expects($this->never())->method('flush');
        $this->hub->expects($this->never())->method('publish');

        $input = new SubmitContributionInput();
        $input->type = 'job_install';
        $input->bomItemId = $this->morphite->getId()->toRfc4122();
        $input->quantity = 1;

        $this->expectException(BadRequestHttpException::class);

        $this->processor->process($input, new Post(), [
            'projectId' => $this->project->getId()->toRfc4122(),
        ]);
    }

    #[Group('issue-154')]
    public function testMaterialContributionWithABomPriceIsRecordedAtTheBomPriceTimesQuantity(): void
    {
        $this->entityManager->expects($this->once())->method('persist')->willReturnCallback(
            fn (GroupIndustryContribution $contribution) => $this->assignId($contribution),
        );
        $this->entityManager->expects($this->once())->method('flush');

        $resource = $this->submitMaterial($this->tritanium, 1000, null);

        self::assertSame('material', $resource->type);
        self::assertSame(1000, $resource->quantity);
        self::assertSame(5500.0, $resource->estimatedValue);
        self::assertSame('pending', $resource->status);
    }

    #[Group('issue-154')]
    public function testMaterialContributionWithABomPriceAndAValueSentIsABadRequestAndNothingIsPersistedNorPublished(): void
    {
        $this->entityManager->expects($this->never())->method('persist');
        $this->entityManager->expects($this->never())->method('flush');
        $this->hub->expects($this->never())->method('publish');

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('Type 34 has a BOM price: a material contribution is valued from it.');

        $this->submitMaterial($this->tritanium, 1000, 9999.99);
    }

    #[Group('issue-168')]
    public function testSubmittedContributionIsPublishedWithItsIdentifierItsPilotItsBomLineItsQuantityAndItsValue(): void
    {
        $character = new Character();
        $character->setName('Kira Voss');
        $this->contributor->method('getMainCharacter')->willReturn($character);
        $contributionId = null;
        $this->entityManager->method('persist')->willReturnCallback(
            function (GroupIndustryContribution $contribution) use (&$contributionId): void {
                $this->assignId($contribution);
                $contributionId = $contribution->getId()->toRfc4122();
            },
        );
        $payloads = $this->capturePublishedPayloads();

        $this->submitMaterial($this->tritanium, 1000, null);

        self::assertCount(1, $payloads);
        self::assertSame('contribution_submitted', $payloads[0]['action']);
        self::assertSame($this->project->getId()->toRfc4122(), $payloads[0]['projectId']);
        self::assertSame(
            [
                'contributionId' => $contributionId,
                'type' => 'material',
                'characterName' => 'Kira Voss',
                'bomItemName' => 'Tritanium',
                'quantity' => 1000,
                'value' => 5500.0,
            ],
            $payloads[0]['data'],
        );
    }

    #[Group('issue-168')]
    public function testContributionWithoutProjectIdentifierIsNotFound(): void
    {
        $this->entityManager->expects($this->never())->method('persist');
        $this->hub->expects($this->never())->method('publish');

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('Project not found');

        $this->processor->process($this->materialInput($this->tritanium, 1000, null), new Post(), []);
    }

    #[Group('issue-168')]
    public function testContributionToAnUnknownProjectIsNotFound(): void
    {
        $this->entityManager->expects($this->never())->method('persist');
        $this->hub->expects($this->never())->method('publish');

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('Project not found');

        $this->processor->process($this->materialInput($this->tritanium, 1000, null), new Post(), [
            'projectId' => Uuid::v4()->toRfc4122(),
        ]);
    }

    #[Group('issue-168')]
    public function testContributionToAProjectOfWhichTheUserIsNotAnAcceptedMemberIsForbidden(): void
    {
        $this->entityManager->expects($this->never())->method('persist');
        $this->hub->expects($this->never())->method('publish');

        $this->expectException(AccessDeniedHttpException::class);
        $this->expectExceptionMessage('You must be an accepted member to contribute');

        $this->processor->process($this->materialInput($this->isogenOfTheOtherProject, 300, 12.0), new Post(), [
            'projectId' => $this->otherProject->getId()->toRfc4122(),
        ]);
    }

    #[Group('issue-168')]
    public function testContributionWithoutBomItemIsABadRequest(): void
    {
        $this->entityManager->expects($this->never())->method('persist');
        $this->hub->expects($this->never())->method('publish');
        $input = $this->materialInput($this->tritanium, 1000, null);
        $input->bomItemId = null;

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('bomItemId is required');

        $this->processor->process($input, new Post(), ['projectId' => $this->project->getId()->toRfc4122()]);
    }

    #[Group('issue-168')]
    public function testContributionToABomItemOfAnotherProjectIsNotFound(): void
    {
        $this->entityManager->expects($this->never())->method('persist');
        $this->hub->expects($this->never())->method('publish');

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('BOM item not found');

        $this->submitMaterial($this->isogenOfTheOtherProject, 300, 12.0);
    }

    #[Group('issue-168')]
    public function testContributionToAnUnknownBomItemIsNotFound(): void
    {
        $this->entityManager->expects($this->never())->method('persist');
        $this->hub->expects($this->never())->method('publish');
        $input = $this->materialInput($this->tritanium, 1000, null);
        $input->bomItemId = Uuid::v4()->toRfc4122();

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('BOM item not found');

        $this->processor->process($input, new Post(), ['projectId' => $this->project->getId()->toRfc4122()]);
    }

    #[Group('issue-168')]
    public function testContributionOfAnUnknownTypeIsABadRequest(): void
    {
        $this->entityManager->expects($this->never())->method('persist');
        $this->hub->expects($this->never())->method('publish');
        $input = $this->materialInput($this->tritanium, 1000, null);
        $input->type = 'donation';

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('Invalid contribution type "donation"');

        $this->processor->process($input, new Post(), ['projectId' => $this->project->getId()->toRfc4122()]);
    }

    private function submitMorphite(int $quantity, ?float $estimatedValue): GroupIndustryContributionResource
    {
        return $this->submitMaterial($this->morphite, $quantity, $estimatedValue);
    }

    private function submitMaterial(GroupIndustryBomItem $bomItem, int $quantity, ?float $estimatedValue): GroupIndustryContributionResource
    {
        return $this->processor->process($this->materialInput($bomItem, $quantity, $estimatedValue), new Post(), [
            'projectId' => $this->project->getId()->toRfc4122(),
        ]);
    }

    private function materialInput(GroupIndustryBomItem $bomItem, int $quantity, ?float $estimatedValue): SubmitContributionInput
    {
        $input = new SubmitContributionInput();
        $input->type = 'material';
        $input->bomItemId = $bomItem->getId()->toRfc4122();
        $input->quantity = $quantity;
        $input->estimatedValue = $estimatedValue;

        return $input;
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
        $property = new \ReflectionProperty($entity, 'id');
        $property->setValue($entity, Uuid::v4());
    }
}
