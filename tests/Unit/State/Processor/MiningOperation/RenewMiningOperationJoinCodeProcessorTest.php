<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\MiningOperation;

use ApiPlatform\Metadata\Post;
use App\ApiResource\MiningOperation\MiningOperationResource;
use App\Entity\MiningOperation;
use App\Entity\User;
use App\Enum\MiningOperationMemberRole;
use App\Enum\MiningOperationMemberStatus;
use App\Enum\MiningOperationStatus;
use App\Service\Mercure\MercurePublisherService;
use App\Service\MiningOperation\MiningOperationAccess;
use App\Service\MiningOperation\MiningPresenceResolver;
use App\State\Processor\MiningOperation\RenewMiningOperationJoinCodeProcessor;
use App\State\Provider\MiningOperation\MiningOperationResourceMapper;
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
use Symfony\Component\Mercure\HubInterface;

#[CoversClass(RenewMiningOperationJoinCodeProcessor::class)]
#[Group('issue-262')]
#[AllowMockObjectsWithoutExpectations]
final class RenewMiningOperationJoinCodeProcessorTest extends TestCase
{
    use BuildsSpodumainOperation;

    private Security&Stub $security;
    private EntityManagerInterface&MockObject $entityManager;
    private HubInterface&MockObject $hub;
    private RenewMiningOperationJoinCodeProcessor $processor;

    protected function setUp(): void
    {
        $this->setUpSpodumainPilots();
        $this->security = $this->createStub(Security::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->hub = $this->createMock(HubInterface::class);
        $this->processor = new RenewMiningOperationJoinCodeProcessor(
            $this->security,
            $this->operationRepositoryReadingOperations(),
            new MiningOperationAccess(),
            new MiningOperationResourceMapper(new MiningPresenceResolver(new MockClock())),
            $this->entityManager,
            new MercurePublisherService($this->hub, new NullLogger()),
        );
    }

    public function testOwnerRenewsTheJoinCodeAndParticipantsAreNotified(): void
    {
        $operation = $this->spodumainOperation();
        $this->addMembership($operation, $this->brann, MiningOperationMemberRole::Member, MiningOperationMemberStatus::Accepted, ['Brann Holt']);
        $this->actAs($this->aria);
        $publishedUpdates = $this->capturePublishedUpdates($this->hub);
        $this->entityManager->expects($this->once())->method('flush');

        $resource = $this->renew($operation);

        self::assertMatchesRegularExpression(self::JOIN_CODE_PATTERN, (string) $resource->joinCode);
        self::assertNotSame(self::JOIN_CODE, $resource->joinCode);
        self::assertSame($operation->getJoinCode(), $resource->joinCode);
        self::assertSame(
            $this->expectedMessages([$this->aria, $this->brann], $operation, 'join_code_renewed', []),
            $this->publishedMessages($publishedUpdates),
        );
    }

    public function testAdminRenewsTheJoinCode(): void
    {
        $operation = $this->spodumainOperation();
        $this->addMembership($operation, $this->brann, MiningOperationMemberRole::Admin, MiningOperationMemberStatus::Accepted, ['Brann Holt']);
        $this->actAs($this->brann);

        $this->renew($operation);

        self::assertMatchesRegularExpression(self::JOIN_CODE_PATTERN, $operation->getJoinCode());
        self::assertNotSame(self::JOIN_CODE, $operation->getJoinCode());
    }

    public function testPlainMemberCannotRenewTheJoinCode(): void
    {
        $operation = $this->spodumainOperation();
        $this->addMembership($operation, $this->brann, MiningOperationMemberRole::Member, MiningOperationMemberStatus::Accepted, ['Brann Holt']);
        $this->actAs($this->brann);
        $this->expectNothingChanged();

        try {
            $this->renew($operation);
            self::fail('A plain member must not renew the join code');
        } catch (AccessDeniedHttpException $refusal) {
            self::assertSame('Only the operation owner or an admin can perform this action', $refusal->getMessage());
        }

        self::assertSame(self::JOIN_CODE, $operation->getJoinCode());
    }

    public function testJoinCodeOfACompletedOperationIsNotRenewed(): void
    {
        $operation = $this->spodumainOperation(MiningOperationStatus::Completed);
        $this->actAs($this->aria);
        $this->expectNothingChanged();

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('This operation is no longer accepting participants');

        $this->renew($operation);
    }

    private function actAs(User $user): void
    {
        $this->security->method('getUser')->willReturn($user);
    }

    private function renew(MiningOperation $operation): MiningOperationResource
    {
        return $this->processor->process(null, new Post(), ['id' => $operation->getId()->toRfc4122()]);
    }

    private function expectNothingChanged(): void
    {
        $this->entityManager->expects($this->never())->method('flush');
        $this->hub->expects($this->never())->method('publish');
    }
}
