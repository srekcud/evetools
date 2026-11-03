<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\MiningOperation;

use ApiPlatform\Metadata\Post;
use App\ApiResource\Input\EmptyInput;
use App\ApiResource\MiningOperation\MiningOperationResource;
use App\Entity\MiningOperation;
use App\Entity\User;
use App\Enum\MarketSide;
use App\Enum\MiningOperationStatus;
use App\Enum\MiningSplitMode;
use App\Service\Mercure\MercurePublisherService;
use App\Service\MiningOperation\MiningOperationAccess;
use App\Service\MiningOperation\MiningOperationSettlement;
use App\Service\MiningOperation\MiningPresenceResolver;
use App\State\Processor\MiningOperation\SettleMiningOperationProcessor;
use App\State\Provider\MiningOperation\MiningOperationResourceMapper;
use App\Tests\Unit\Service\Mining\BuildsOreValueServiceOnSdeOres;
use App\Tests\Unit\Service\MiningOperation\BuildsLedgerSnapshots;
use App\Tests\Unit\Service\MiningOperation\BuildsMiningPayments;
use App\Tests\Unit\Service\MiningOperation\BuildsSpodumainOperation;
use App\Tests\Unit\Service\MiningOperation\BuildsValuedSpodumainOperation;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Mercure\HubInterface;

#[CoversClass(SettleMiningOperationProcessor::class)]
#[CoversClass(MiningOperationSettlement::class)]
#[Group('issue-265')]
#[AllowMockObjectsWithoutExpectations]
final class SettleMiningOperationProcessorTest extends TestCase
{
    use BuildsSpodumainOperation;
    use BuildsLedgerSnapshots;
    use BuildsOreValueServiceOnSdeOres;
    use BuildsValuedSpodumainOperation;
    use BuildsMiningPayments;

    private Security&Stub $security;
    private EntityManagerInterface&MockObject $entityManager;
    private HubInterface&MockObject $hub;

    protected function setUp(): void
    {
        $this->setUpSpodumainPilots();
        $this->security = $this->createStub(Security::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->hub = $this->createMock(HubInterface::class);
    }

    public function testOwnerSettlesTheOperationOnceEveryPaymentIsPaidAndEveryParticipantIsNotified(): void
    {
        $operation = $this->g1OperationWithEveryPaymentPaid();
        $this->actAs($this->aria);
        $publishedUpdates = $this->capturePublishedUpdates($this->hub);
        $this->entityManager->expects($this->once())->method('flush');

        $resource = $this->settle($operation);

        self::assertSame(
            [
                'resource' => ['settled', self::SETTLED_AT, 'Aria Solberg'],
                'operation' => ['settled', self::SETTLED_AT, 'Aria Solberg', true],
            ],
            [
                'resource' => [$resource->status, $resource->archivedAt?->format(\DateTimeInterface::ATOM), $resource->archivedByName],
                'operation' => [$operation->getStatus()->value, $operation->getArchivedAt()?->format(\DateTimeInterface::ATOM), $operation->getArchivedByName(), $operation->getStatus()->isArchived()],
            ],
        );
        self::assertSame(
            $this->expectedMessages([$this->aria, $this->brann, $this->cyra], $operation, 'operation_settled', []),
            $this->publishedMessages($publishedUpdates),
        );
    }

    public function testOperationWithAnUnpaidPaymentIsNotSettledAndTheCountOfUnpaidPaymentsIsGiven(): void
    {
        $operation = $this->g1Operation();
        $this->paymentAt($operation, 0)->markPaid($this->aria, 'Aria Solberg', new \DateTimeImmutable(self::PAID_AT));
        $this->paymentAt($operation, 1)->markPaid($this->aria, 'Aria Solberg', new \DateTimeImmutable(self::PAID_AT));
        $this->actAs($this->aria);
        $this->expectNothingChanged();

        self::assertSame(
            ['refusal' => 'Every payment must be marked paid before settling: 1 left', 'status' => 'shares', 'archivedAt' => null],
            ['refusal' => $this->refusalOf($operation), 'status' => $operation->getStatus()->value, 'archivedAt' => $operation->getArchivedAt()],
        );
    }

    public function testOperationWithoutAnyPaymentIsSettledAtOnce(): void
    {
        $operation = $this->g1Operation();
        $operation->getPayments()->clear();
        $this->actAs($this->aria);

        self::assertSame('settled', $this->settle($operation)->status);
    }

    public function testClosingOperationCannotBeSettled(): void
    {
        $operation = $this->withCyraBoostingForTenPercent($this->e1Operation());
        $this->actAs($this->aria);
        $this->expectNothingChanged();

        self::assertSame('Only an operation with frozen shares can be settled', $this->refusalOf($operation));
    }

    public function testSettledOperationCannotBeSettledAgain(): void
    {
        $operation = $this->g1OperationWithEveryPaymentPaid();
        $operation->setStatus(MiningOperationStatus::Settled);
        $this->actAs($this->aria);
        $this->expectNothingChanged();

        self::assertSame('Only an operation with frozen shares can be settled', $this->refusalOf($operation));
    }

    public function testClosingEachForThemselvesOperationCannotBeSettled(): void
    {
        $operation = $this->e1Operation(splitMode: MiningSplitMode::EachForThemselves);
        $operation->updateValuationSettings(valuationSide: MarketSide::Sell);
        $this->actAs($this->aria);

        self::assertSame('Only an operation with frozen shares can be settled', $this->refusalOf($operation));
    }

    public function testPlainMemberCannotSettleTheOperation(): void
    {
        $operation = $this->g1OperationWithEveryPaymentPaid();
        $this->actAs($this->brann);
        $this->expectNothingChanged();

        $this->expectException(AccessDeniedHttpException::class);
        $this->expectExceptionMessage('Only the operation owner or an admin can perform this action');

        $this->settle($operation);
    }

    private function actAs(User $user): void
    {
        $this->security->method('getUser')->willReturn($user);
    }

    private function settle(MiningOperation $operation): MiningOperationResource
    {
        $processor = new SettleMiningOperationProcessor(
            $this->security,
            $this->operationRepositoryReadingOperations(),
            new MiningOperationAccess(),
            new MiningOperationSettlement($this->entityManager, new MockClock(self::SETTLED_AT)),
            new MiningOperationResourceMapper(new MiningPresenceResolver(new MockClock())),
            new MercurePublisherService($this->hub, new NullLogger()),
        );

        return $processor->process(new EmptyInput(), new Post(), ['id' => $operation->getId()?->toRfc4122()]);
    }

    private function refusalOf(MiningOperation $operation): ?string
    {
        try {
            $this->settle($operation);
        } catch (BadRequestHttpException $refusal) {
            return $refusal->getMessage();
        }

        return null;
    }

    private function expectNothingChanged(): void
    {
        $this->entityManager->expects($this->never())->method('flush');
        $this->hub->expects($this->never())->method('publish');
    }
}
