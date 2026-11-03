<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\MiningOperation;

use ApiPlatform\Metadata\Post;
use App\ApiResource\Input\EmptyInput;
use App\ApiResource\MiningOperation\MiningOperationResource;
use App\Entity\MiningOperation;
use App\Entity\User;
use App\Enum\MarketSide;
use App\Enum\MiningSplitMode;
use App\Service\Mercure\MercurePublisherService;
use App\Service\MiningOperation\MiningOperationAccess;
use App\Service\MiningOperation\MiningPresenceResolver;
use App\Service\MiningOperation\MiningShareFreezer;
use App\State\Processor\MiningOperation\CompleteMiningOperationProcessor;
use App\State\Provider\MiningOperation\MiningOperationResourceMapper;
use App\Tests\Unit\Service\Mining\BuildsOreValueServiceOnSdeOres;
use App\Tests\Unit\Service\MiningOperation\BuildsLedgerSnapshots;
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
use Symfony\Component\Mercure\HubInterface;

#[CoversClass(CompleteMiningOperationProcessor::class)]
#[Group('issue-265')]
#[AllowMockObjectsWithoutExpectations]
final class CompleteMiningOperationProcessorTest extends TestCase
{
    use BuildsSpodumainOperation;
    use BuildsLedgerSnapshots;
    use BuildsOreValueServiceOnSdeOres;
    use BuildsValuedSpodumainOperation;

    private const string COMPLETED_AT = '2026-11-05T21:58:00+00:00';

    private Security&Stub $security;
    private EntityManagerInterface&MockObject $entityManager;
    private HubInterface&MockObject $hub;
    private MiningOperation $operation;

    protected function setUp(): void
    {
        $this->setUpSpodumainPilots();
        $this->operation = $this->e1Operation(splitMode: MiningSplitMode::EachForThemselves);
        $this->operation->updateValuationSettings(valuationSide: MarketSide::Sell);
        $this->security = $this->createStub(Security::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->hub = $this->createMock(HubInterface::class);
    }

    public function testOwnerCompletesTheEachForThemselvesOperationAndEveryParticipantIsNotified(): void
    {
        $this->actAs($this->aria);
        $publishedUpdates = $this->capturePublishedUpdates($this->hub);
        $this->entityManager->expects($this->once())->method('flush');

        $resource = $this->complete();

        self::assertSame(
            ['completed', self::COMPLETED_AT, 'Aria Solberg', self::COMPLETED_AT],
            [
                $resource->status,
                $resource->archivedAt?->format(\DateTimeInterface::ATOM),
                $resource->archivedByName,
                $resource->sharesFrozenAt?->format(\DateTimeInterface::ATOM),
            ],
        );
        self::assertSame(
            $this->expectedMessages([$this->aria, $this->brann, $this->cyra], $this->operation, 'operation_completed', []),
            $this->publishedMessages($publishedUpdates),
        );
    }

    public function testPlainMemberCannotCompleteTheOperation(): void
    {
        $this->actAs($this->brann);
        $this->entityManager->expects($this->never())->method('flush');
        $this->hub->expects($this->never())->method('publish');

        $this->expectException(AccessDeniedHttpException::class);
        $this->expectExceptionMessage('Only the operation owner or an admin can perform this action');

        $this->complete();
    }

    private function actAs(User $user): void
    {
        $this->security->method('getUser')->willReturn($user);
    }

    private function complete(): MiningOperationResource
    {
        $clock = new MockClock(self::COMPLETED_AT);
        $processor = new CompleteMiningOperationProcessor(
            $this->security,
            $this->operationRepositoryReadingOperations(),
            new MiningOperationAccess(),
            new MiningShareFreezer(
                $this->valuatorOn($this->jitaMarketSyncedAt($this->jitaSellPrices()), $clock),
                $this->typeNameResolverOfSdeOres(),
                $this->entityManager,
                $clock,
            ),
            new MiningOperationResourceMapper(new MiningPresenceResolver(new MockClock())),
            new MercurePublisherService($this->hub, new NullLogger()),
        );

        return $processor->process(new EmptyInput(), new Post(), ['id' => $this->operation->getId()?->toRfc4122()]);
    }
}
