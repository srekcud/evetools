<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\GroupIndustry;

use ApiPlatform\Metadata\Post;
use App\ApiResource\GroupIndustry\GroupIndustrySaleResource;
use App\ApiResource\Input\GroupIndustry\RecordSaleInput;
use App\Entity\GroupIndustrySale;
use App\Enum\GroupProjectStatus;
use App\Service\Mercure\MercurePublisherService;
use App\State\Processor\GroupIndustry\RecordSaleProcessor;
use App\State\Provider\GroupIndustry\GroupProjectAccessChecker;
use App\Tests\Unit\State\Provider\GroupIndustry\GroupProjectAccessProfiles;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
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
    use GroupProjectAccessProfiles;

    private const int ORCA_TYPE_ID = 28606;
    private const int SOLD_QUANTITY = 5;
    private const float UNIT_PRICE = 1200000.0;
    private const string COMPLETED_PROJECT_REFUSAL = '400 Cannot record a sale on a completed project';

    private Security&Stub $security;
    private EntityManagerInterface&MockObject $entityManager;
    private HubInterface&MockObject $hub;
    private RecordSaleProcessor $processor;
    private int $persistedSales = 0;

    protected function setUp(): void
    {
        $this->createSabreBatch();
        $this->security = $this->createStub(Security::class);

        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->entityManager->method('persist')->willReturnCallback(function (GroupIndustrySale $sale): void {
            (new \ReflectionProperty($sale, 'id'))->setValue($sale, Uuid::v4());
            ++$this->persistedSales;
        });
        $this->hub = $this->createMock(HubInterface::class);

        $this->processor = new RecordSaleProcessor(
            $this->security,
            $this->sabreBatchProjectRepository(),
            new GroupProjectAccessChecker($this->sabreBatchMemberRepository()),
            $this->entityManager,
            new MercurePublisherService($this->hub, new NullLogger()),
        );
    }

    public function testAdminCannotRecordASaleOnACompletedProject(): void
    {
        $this->security->method('getUser')->willReturn($this->userWithProfile(self::ACCEPTED_ADMIN));
        $this->sabreBatch->setStatus(GroupProjectStatus::Completed);

        $this->entityManager->expects($this->never())->method('flush');
        $this->hub->expects($this->never())->method('publish');

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('Cannot record a sale on a completed project');

        $this->recordOrcaSale($this->sabreBatch->getId());
    }

    public function testAdminRecordsASaleOnASellingProject(): void
    {
        $this->security->method('getUser')->willReturn($this->userWithProfile(self::ACCEPTED_ADMIN));
        $this->sabreBatch->setStatus(GroupProjectStatus::Selling);

        $this->entityManager->expects($this->once())->method('flush');

        $resource = $this->recordOrcaSale($this->sabreBatch->getId());

        self::assertSame(self::ORCA_TYPE_ID, $resource->typeId);
        self::assertSame('Orca', $resource->typeName);
        self::assertSame(5, $resource->quantity);
        self::assertSame(1200000.0, $resource->unitPrice);
        self::assertSame(6000000.0, $resource->totalPrice);
        self::assertSame('Jita IV - Moon 4', $resource->venue);
        self::assertSame(1, $this->persistedSales);
    }

    #[Group('issue-164')]
    public function testAdminRecordsAnIshtarSaleAtItsAtomSaleDate(): void
    {
        $this->security->method('getUser')->willReturn($this->userWithProfile(self::ACCEPTED_ADMIN));

        $resource = $this->recordIshtarSale(['soldAt' => '2026-10-25T14:30:00+00:00']);

        self::assertSame(
            [0, 'Ishtar', 5, 1200000.0, 6000000.0, '2026-10-25T14:30:00+00:00'],
            [$resource->typeId, $resource->typeName, $resource->quantity, $resource->unitPrice, $resource->totalPrice, $resource->soldAt],
        );
    }

    #[Group('issue-164')]
    public function testSaleDateWrittenZIsRecordedAsUtc(): void
    {
        $this->security->method('getUser')->willReturn($this->userWithProfile(self::ACCEPTED_ADMIN));

        $resource = $this->recordIshtarSale(['soldAt' => '2026-10-25T14:30:00Z']);

        self::assertSame('2026-10-25T14:30:00+00:00', $resource->soldAt);
    }

    #[Group('issue-164')]
    public function testSaleWithoutSaleDateIsDatedAtItsRecording(): void
    {
        $this->security->method('getUser')->willReturn($this->userWithProfile(self::ACCEPTED_ADMIN));
        $recordingStart = new \DateTimeImmutable('-1 second');

        $resource = $this->recordIshtarSale([]);

        $soldAt = new \DateTimeImmutable($resource->soldAt);
        self::assertTrue($soldAt >= $recordingStart && $soldAt <= new \DateTimeImmutable('+1 second'), $resource->soldAt);
    }

    #[Group('issue-164')]
    public function testFreeIshtarSaleIsRecordedWithAZeroTotal(): void
    {
        $this->security->method('getUser')->willReturn($this->userWithProfile(self::ACCEPTED_ADMIN));

        $resource = $this->recordIshtarSale(['unitPrice' => 0.0]);

        self::assertSame([0.0, 0.0, 1], [$resource->unitPrice, $resource->totalPrice, $this->persistedSales]);
    }

    public static function recordSaleMatrix(): iterable
    {
        yield 'A1 owner' => [self::OWNER, self::GRANTED];
        yield 'A2 accepted admin' => [self::ACCEPTED_ADMIN, self::GRANTED];
        yield 'A3 accepted member' => [self::ACCEPTED_MEMBER, self::ADMIN_OR_OWNER_ONLY];
        yield 'A4 pending member' => [self::PENDING_MEMBER, self::ADMIN_OR_OWNER_ONLY];
        yield 'A5 owner corporation without membership' => [self::OWNER_CORPORATION_NON_MEMBER, self::ADMIN_OR_OWNER_ONLY];
        yield 'A6 owner corporation pending member' => [self::OWNER_CORPORATION_PENDING_MEMBER, self::ADMIN_OR_OWNER_ONLY];
        yield 'A7 owner corporation removed former admin' => [self::OWNER_CORPORATION_REMOVED_FORMER_ADMIN, self::ADMIN_OR_OWNER_ONLY];
        yield 'A8 removed member' => [self::REMOVED_MEMBER, self::ADMIN_OR_OWNER_ONLY];
        yield 'A9 stranger' => [self::STRANGER, self::ADMIN_OR_OWNER_ONLY];
        yield 'A10 user without corporation' => [self::USER_WITHOUT_CORPORATION, self::ADMIN_OR_OWNER_ONLY];
        yield 'A12 accepted admin of a completed project' => [self::ACCEPTED_ADMIN_OF_COMPLETED_PROJECT, self::COMPLETED_PROJECT_REFUSAL];
        yield 'A13 stranger to a completed project' => [self::STRANGER_TO_COMPLETED_PROJECT, self::ADMIN_OR_OWNER_ONLY];
    }

    #[DataProvider('recordSaleMatrix')]
    public function testOnlyTheOwnerAndAcceptedAdminsRecordASaleOnAProjectInProgress(string $profile, string $expectedOutcome): void
    {
        $this->security->method('getUser')->willReturn($this->userWithProfile($profile));

        $outcome = $this->outcomeOf(fn () => $this->recordOrcaSale($this->sabreBatch->getId()));

        self::assertSame([$expectedOutcome, $expectedOutcome === self::GRANTED ? 1 : 0], [$outcome, $this->persistedSales]);
    }

    public function testSaleOnAnUnknownProjectIsNotFound(): void
    {
        $this->security->method('getUser')->willReturn($this->userWithProfile(self::STRANGER));

        self::assertSame([self::PROJECT_NOT_FOUND, 0], [$this->outcomeOf(fn () => $this->recordOrcaSale(Uuid::v4())), $this->persistedSales]);
    }

    public function testSaleWithoutUserIsUnauthorized(): void
    {
        $this->security->method('getUser')->willReturn(null);

        self::assertSame([self::UNAUTHORIZED, 0], [$this->outcomeOf(fn () => $this->recordOrcaSale($this->sabreBatch->getId())), $this->persistedSales]);
    }

    private function recordIshtarSale(array $changes): GroupIndustrySaleResource
    {
        $this->sabreBatch->setStatus(GroupProjectStatus::Selling);

        $input = new RecordSaleInput();
        $fields = array_merge(['typeId' => 0, 'typeName' => 'Ishtar', 'quantity' => 5, 'unitPrice' => 1200000.0], $changes);
        foreach ($fields as $field => $value) {
            $input->{$field} = $value;
        }

        return $this->processor->process($input, new Post(), [
            'projectId' => $this->sabreBatch->getId()->toRfc4122(),
        ]);
    }

    private function recordOrcaSale(Uuid $projectId): GroupIndustrySaleResource
    {
        $input = new RecordSaleInput();
        $input->typeId = self::ORCA_TYPE_ID;
        $input->typeName = 'Orca';
        $input->quantity = self::SOLD_QUANTITY;
        $input->unitPrice = self::UNIT_PRICE;
        $input->venue = 'Jita IV - Moon 4';
        $input->soldAt = '2026-10-20T18:30:00+00:00';

        return $this->processor->process($input, new Post(), [
            'projectId' => $projectId->toRfc4122(),
        ]);
    }
}
