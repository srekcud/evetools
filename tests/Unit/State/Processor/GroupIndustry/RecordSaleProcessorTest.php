<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\GroupIndustry;

use ApiPlatform\Metadata\Post;
use App\ApiResource\GroupIndustry\GroupIndustrySaleResource;
use App\ApiResource\Input\GroupIndustry\RecordSaleInput;
use App\Entity\GroupIndustryProject;
use App\Entity\GroupIndustryProjectMember;
use App\Entity\GroupIndustrySale;
use App\Entity\User;
use App\Enum\GroupMemberRole;
use App\Enum\GroupMemberStatus;
use App\Enum\GroupProjectStatus;
use App\Repository\GroupIndustryProjectMemberRepository;
use App\Repository\GroupIndustryProjectRepository;
use App\Service\Mercure\MercurePublisherService;
use App\State\Processor\GroupIndustry\RecordSaleProcessor;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Uid\Uuid;

#[CoversClass(RecordSaleProcessor::class)]
#[AllowMockObjectsWithoutExpectations]
class RecordSaleProcessorTest extends TestCase
{
    private const int ORCA_TYPE_ID = 28606;
    private const int SOLD_QUANTITY = 5;
    private const float UNIT_PRICE = 1200000.0;

    private EntityManagerInterface&MockObject $entityManager;
    private HubInterface&MockObject $hub;
    private RecordSaleProcessor $processor;
    private GroupIndustryProject $project;

    protected function setUp(): void
    {
        $admin = $this->createStub(User::class);

        $this->project = new GroupIndustryProject();
        $this->project->setOwner($this->createStub(User::class));
        $this->assignId($this->project);

        $adminMember = new GroupIndustryProjectMember();
        $adminMember->setUser($admin);
        $adminMember->setRole(GroupMemberRole::Admin);
        $adminMember->setStatus(GroupMemberStatus::Accepted);
        $adminMember->setProject($this->project);
        $this->assignId($adminMember);

        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($admin);
        $projectRepository = $this->createStub(GroupIndustryProjectRepository::class);
        $projectRepository->method('find')->willReturn($this->project);
        $memberRepository = $this->createStub(GroupIndustryProjectMemberRepository::class);
        $memberRepository->method('findOneBy')->willReturn($adminMember);

        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->hub = $this->createMock(HubInterface::class);

        $this->processor = new RecordSaleProcessor(
            $security,
            $projectRepository,
            $memberRepository,
            $this->entityManager,
            new MercurePublisherService($this->hub, new NullLogger()),
        );
    }

    public function testAdminCannotRecordASaleOnACompletedProject(): void
    {
        $this->project->setStatus(GroupProjectStatus::Completed);

        $this->entityManager->expects($this->never())->method('persist');
        $this->entityManager->expects($this->never())->method('flush');
        $this->hub->expects($this->never())->method('publish');

        $this->expectException(BadRequestHttpException::class);

        $this->recordOrcaSale();
    }

    public function testAdminRecordsASaleOnASellingProject(): void
    {
        $this->project->setStatus(GroupProjectStatus::Selling);

        $this->entityManager->expects($this->once())->method('persist')->willReturnCallback(
            fn (GroupIndustrySale $sale) => $this->assignId($sale),
        );
        $this->entityManager->expects($this->once())->method('flush');

        $resource = $this->recordOrcaSale();

        self::assertSame(self::ORCA_TYPE_ID, $resource->typeId);
        self::assertSame('Orca', $resource->typeName);
        self::assertSame(5, $resource->quantity);
        self::assertSame(1200000.0, $resource->unitPrice);
        self::assertSame(6000000.0, $resource->totalPrice);
        self::assertSame('Jita IV - Moon 4', $resource->venue);
    }

    private function recordOrcaSale(): GroupIndustrySaleResource
    {
        $input = new RecordSaleInput();
        $input->typeId = self::ORCA_TYPE_ID;
        $input->typeName = 'Orca';
        $input->quantity = self::SOLD_QUANTITY;
        $input->unitPrice = self::UNIT_PRICE;
        $input->venue = 'Jita IV - Moon 4';
        $input->soldAt = '2026-10-20T18:30:00+00:00';

        return $this->processor->process($input, new Post(), [
            'projectId' => $this->project->getId()->toRfc4122(),
        ]);
    }

    private function assignId(object $entity): void
    {
        $property = new \ReflectionProperty($entity, 'id');
        $property->setValue($entity, Uuid::v4());
    }
}
