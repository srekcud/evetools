<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\MiningOperation;

use ApiPlatform\Metadata\Post;
use App\ApiResource\Input\MiningOperation\StartMiningOperationInput;
use App\ApiResource\MiningOperation\MiningOperationResource;
use App\Entity\MiningOperation;
use App\Entity\User;
use App\Enum\MiningLedgerSnapshotKind;
use App\Service\Mercure\MercurePublisherService;
use App\Service\MiningOperation\MiningOperationAccess;
use App\Service\MiningOperation\MiningPresenceResolver;
use App\State\Processor\MiningOperation\StartMiningOperationProcessor;
use App\State\Provider\MiningOperation\MiningOperationResourceMapper;
use App\Tests\Unit\Service\MiningOperation\BuildsLedgerSnapshots;
use App\Tests\Unit\Service\MiningOperation\BuildsSpodumainOperation;
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
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Mercure\HubInterface;

#[CoversClass(StartMiningOperationProcessor::class)]
#[Group('issue-263')]
#[AllowMockObjectsWithoutExpectations]
final class StartMiningOperationProcessorTest extends TestCase
{
    use BuildsSpodumainOperation;
    use BuildsLedgerSnapshots;

    private Security&Stub $security;
    private EntityManagerInterface&MockObject $entityManager;
    private HubInterface&MockObject $hub;
    private \ArrayObject $dispatched;
    private MiningOperation $operation;
    private StartMiningOperationProcessor $processor;

    protected function setUp(): void
    {
        $this->setUpSpodumainPilots();
        $this->operation = $this->spodumainOperationWithBrann();
        $this->security = $this->createStub(Security::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->hub = $this->createMock(HubInterface::class);
        $this->dispatched = new \ArrayObject();
        $this->processor = new StartMiningOperationProcessor(
            $this->security,
            $this->operationRepositoryReadingOperations(),
            new MiningOperationAccess(),
            $this->lifecycleWith($this->entityManager, $this->dispatched, new MockClock(self::STARTED_AT)),
            new MiningOperationResourceMapper(new MiningPresenceResolver(new MockClock())),
            new MercurePublisherService($this->hub, new NullLogger()),
        );
    }

    public function testOwnerStartsTheOperationInS1AndEveryParticipantIsNotified(): void
    {
        $this->actAs($this->aria);
        $publishedUpdates = $this->capturePublishedUpdates($this->hub);
        $this->identifyingOnFlush($this->entityManager);

        $resource = $this->start(self::S1);

        self::assertSame(
            ['in_progress', self::STARTED_AT, null, self::S1, self::S1_NAME, null, 2, 4],
            [
                $resource->status,
                $resource->startedAt?->format(\DateTimeInterface::ATOM),
                $resource->endedAt,
                $resource->solarSystemId,
                $resource->solarSystemName,
                $resource->myDepartedAt,
                $resource->participantsCount,
                $resource->charactersCount,
            ],
        );
        self::assertCount(4, $this->snapshotStatesOf($this->operation, MiningLedgerSnapshotKind::Start));
        self::assertCount(4, $this->dispatched);
        self::assertSame(
            $this->expectedMessages([$this->aria, $this->brann], $this->operation, 'operation_started', []),
            $this->publishedMessages($publishedUpdates),
        );
    }

    public function testOwnerStartsTheOperationWithoutSolarSystem(): void
    {
        $this->actAs($this->aria);
        $this->identifyingOnFlush($this->entityManager);

        $resource = $this->start(null);

        self::assertSame(['in_progress', null, null], [$resource->status, $resource->solarSystemId, $resource->solarSystemName]);
    }

    public function testPlainMemberCannotStartTheOperation(): void
    {
        $this->actAs($this->brann);
        $this->expectNothingChanged();

        $this->expectRefusal(AccessDeniedHttpException::class, 'Only the operation owner or an admin can perform this action');

        $this->start(self::S1);
    }

    public function testUnknownSolarSystemIsRefused(): void
    {
        $this->actAs($this->aria);
        $this->expectNothingChanged();

        $this->expectRefusal(BadRequestHttpException::class, 'Unknown solar system');

        $this->start(self::UNKNOWN_SOLAR_SYSTEM);
    }

    public function testOperationIdThatIsNotAUuidIsNotFound(): void
    {
        $this->actAs($this->aria);

        $this->expectRefusal(NotFoundHttpException::class, 'Mining operation not found');

        $this->processor->process($this->input(self::S1), new Post(), ['id' => self::NOT_A_UUID]);
    }

    public function testAnonymousRequestIsUnauthorized(): void
    {
        $this->security->method('getUser')->willReturn(null);

        $this->expectException(UnauthorizedHttpException::class);

        $this->start(self::S1);
    }

    private function actAs(User $user): void
    {
        $this->security->method('getUser')->willReturn($user);
    }

    private function start(?int $solarSystemId): MiningOperationResource
    {
        return $this->processor->process($this->input($solarSystemId), new Post(), ['id' => $this->operation->getId()->toRfc4122()]);
    }

    private function input(?int $solarSystemId): StartMiningOperationInput
    {
        $input = new StartMiningOperationInput();
        $input->solarSystemId = $solarSystemId;

        return $input;
    }

    private function expectNothingChanged(): void
    {
        $this->entityManager->expects($this->never())->method('flush');
        $this->hub->expects($this->never())->method('publish');
    }

    private function expectRefusal(string $exceptionClass, string $message): void
    {
        $this->expectException($exceptionClass);
        $this->expectExceptionMessage($message);
    }
}
