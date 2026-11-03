<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\MiningOperation;

use ApiPlatform\Metadata\Post;
use App\ApiResource\Input\EmptyInput;
use App\ApiResource\MiningOperation\MiningOperationResource;
use App\Entity\MiningOperation;
use App\Entity\User;
use App\Service\Mercure\MercurePublisherService;
use App\Service\MiningOperation\MiningOperationAccess;
use App\Service\MiningOperation\MiningPresenceResolver;
use App\Service\MiningOperation\MiningShareFreezer;
use App\State\Processor\MiningOperation\FreezeMiningSharesProcessor;
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
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Mercure\Exception\RuntimeException;
use Symfony\Component\Mercure\HubInterface;

#[CoversClass(FreezeMiningSharesProcessor::class)]
#[Group('issue-264')]
#[AllowMockObjectsWithoutExpectations]
final class FreezeMiningSharesProcessorTest extends TestCase
{
    use BuildsSpodumainOperation;
    use BuildsLedgerSnapshots;
    use BuildsOreValueServiceOnSdeOres;
    use BuildsValuedSpodumainOperation;

    private Security&Stub $security;
    private EntityManagerInterface&MockObject $entityManager;
    private HubInterface&MockObject $hub;
    private MiningOperation $operation;

    protected function setUp(): void
    {
        $this->setUpSpodumainPilots();
        $this->operation = $this->withCyraBoostingForTenPercent($this->e1Operation());
        $this->security = $this->createStub(Security::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->hub = $this->createMock(HubInterface::class);
    }

    public function testOwnerFreezesTheSharesAndEveryParticipantIsNotified(): void
    {
        $this->actAs($this->aria);
        $publishedUpdates = $this->capturePublishedUpdates($this->hub);
        $this->entityManager->expects($this->once())->method('flush');

        $resource = $this->freeze($this->processorLoggingTo(new NullLogger()));

        self::assertSame(
            ['shares', self::FROZEN_AT],
            [$resource->status, $resource->sharesFrozenAt?->format(\DateTimeInterface::ATOM)],
        );
        self::assertSame(
            $this->expectedMessages([$this->aria, $this->brann, $this->cyra], $this->operation, 'shares_frozen', []),
            $this->publishedMessages($publishedUpdates),
        );
    }

    public function testPlainMemberCannotFreezeTheShares(): void
    {
        $this->actAs($this->brann);
        $this->entityManager->expects($this->never())->method('flush');
        $this->hub->expects($this->never())->method('publish');

        $this->expectException(AccessDeniedHttpException::class);
        $this->expectExceptionMessage('Only the operation owner or an admin can perform this action');

        $this->freeze($this->processorLoggingTo(new NullLogger()));
    }

    public function testSharesStayFrozenWhenTheMercureHubIsDownAndTheFailureIsLogged(): void
    {
        $this->actAs($this->aria);
        $this->hub->method('publish')->willThrowException(new RuntimeException('Mercure hub unreachable'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->exactly(3))->method('warning')->with('Failed to publish mining operation Mercure update');

        $resource = $this->freeze($this->processorLoggingTo($logger));

        self::assertSame('shares', $resource->status);
    }

    private function processorLoggingTo(LoggerInterface $logger): FreezeMiningSharesProcessor
    {
        $clock = new MockClock(self::FROZEN_AT);

        return new FreezeMiningSharesProcessor(
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
            new MercurePublisherService($this->hub, $logger),
        );
    }

    private function actAs(User $user): void
    {
        $this->security->method('getUser')->willReturn($user);
    }

    private function freeze(FreezeMiningSharesProcessor $processor): MiningOperationResource
    {
        return $processor->process(new EmptyInput(), new Post(), ['id' => $this->operation->getId()->toRfc4122()]);
    }
}
