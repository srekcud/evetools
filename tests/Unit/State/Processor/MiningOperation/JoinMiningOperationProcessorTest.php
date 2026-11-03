<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\MiningOperation;

use ApiPlatform\Metadata\Post;
use App\ApiResource\Input\MiningOperation\JoinMiningOperationInput;
use App\ApiResource\MiningOperation\MiningOperationInvitationResource;
use App\Entity\MiningOperation;
use App\Entity\MiningOperationMember;
use App\Entity\User;
use App\Enum\MiningOperationMemberRole;
use App\Enum\MiningOperationMemberStatus;
use App\Service\Mercure\MercurePublisherService;
use App\Service\MiningOperation\MiningOperationEnrolment;
use App\State\Processor\MiningOperation\JoinMiningOperationProcessor;
use App\State\Provider\MiningOperation\MiningOperationResourceMapper;
use App\Tests\Unit\Service\MiningOperation\BuildsSpodumainOperation;
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
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Mercure\Exception\RuntimeException;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Uid\Uuid;

#[CoversClass(JoinMiningOperationProcessor::class)]
#[Group('issue-262')]
#[AllowMockObjectsWithoutExpectations]
final class JoinMiningOperationProcessorTest extends TestCase
{
    use BuildsSpodumainOperation;

    private Security&Stub $security;
    private EntityManagerInterface&MockObject $entityManager;
    private HubInterface&MockObject $hub;
    private MiningOperation $operation;

    protected function setUp(): void
    {
        $this->setUpSpodumainPilots();
        $this->operation = $this->spodumainOperation();
        $this->security = $this->createStub(Security::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->entityManager->method('flush')->willReturnCallback(function (): void {
            foreach ($this->operations as $operation) {
                foreach ($operation->getMembers() as $member) {
                    if ($member->getId() === null) {
                        (new \ReflectionProperty(MiningOperationMember::class, 'id'))->setValue($member, Uuid::v4());
                    }
                }
            }
        });
        $this->hub = $this->createMock(HubInterface::class);
    }

    public function testBrannFromTheCorporationJoinsAsAcceptedSecondParticipant(): void
    {
        $this->actAs($this->brann);

        $invitation = $this->join(self::JOIN_CODE, ['Brann Holt', 'Brann II']);

        self::assertSame(self::SPODUMAIN_LABEL, $invitation->label);
        self::assertSame('member', $invitation->myRole);
        self::assertSame('accepted', $invitation->myStatus);
        self::assertSame(2, $invitation->participantsCount);
        self::assertSame(4, $this->enrolledCharactersCountOfAcceptedMembers($this->operation));
    }

    public function testCyraFromAnotherCorporationJoinsAsPendingAndOwnerParticipantsAndCyraAreNotified(): void
    {
        $this->addMembership($this->operation, $this->brann, MiningOperationMemberRole::Member, MiningOperationMemberStatus::Accepted, ['Brann Holt', 'Brann II']);
        $this->actAs($this->cyra);
        $publishedUpdates = $this->capturePublishedUpdates($this->hub);

        $invitation = $this->join(self::JOIN_CODE, ['Cyra Lune']);

        self::assertSame('pending', $invitation->myStatus);
        self::assertSame('member', $invitation->myRole);
        self::assertSame(2, $invitation->participantsCount);
        $cyraMembership = $this->membershipOf($this->operation, $this->cyra);
        self::assertNotNull($cyraMembership);
        self::assertSame(
            $this->expectedMessages([$this->aria, $this->brann, $this->cyra], $this->operation, 'member_joined', [
                'memberId' => $cyraMembership->getId()->toRfc4122(),
                'status' => 'pending',
            ]),
            $this->publishedMessages($publishedUpdates),
        );
    }

    public function testJoinSucceedsAndAWarningIsLoggedWhenTheMercureHubIsDown(): void
    {
        $this->actAs($this->cyra);
        $this->hub->method('publish')->willThrowException(new RuntimeException('Mercure hub unreachable'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->atLeastOnce())->method('warning');

        $invitation = $this->processorPublishingThrough($logger)->process($this->input(['Cyra Lune']), new Post(), ['code' => self::JOIN_CODE]);

        self::assertSame('pending', $invitation->myStatus);
    }

    public function testUnknownJoinCodeIsNotFound(): void
    {
        $this->actAs($this->brann);
        $this->entityManager->expects($this->never())->method('flush');

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('Mining operation not found');

        $this->join(self::UNKNOWN_JOIN_CODE, ['Brann Holt']);
    }

    public function testPreviousJoinCodeIsNotFoundOnceRenewedAndTheNewOneJoins(): void
    {
        $this->operation->renewJoinCode();
        $this->actAs($this->brann);

        try {
            $this->join(self::JOIN_CODE, ['Brann Holt']);
            self::fail('The previous join code must no longer reach the operation');
        } catch (NotFoundHttpException $notFound) {
            self::assertSame('Mining operation not found', $notFound->getMessage());
        }

        self::assertSame('accepted', $this->join($this->operation->getJoinCode(), ['Brann Holt'])->myStatus);
    }

    public function testAnonymousJoinIsUnauthorized(): void
    {
        $this->security->method('getUser')->willReturn(null);

        $this->expectException(UnauthorizedHttpException::class);

        $this->join(self::JOIN_CODE, []);
    }

    private function actAs(User $user): void
    {
        $this->security->method('getUser')->willReturn($user);
    }

    private function join(string $code, array $characterNames): MiningOperationInvitationResource
    {
        return $this->processorPublishingThrough(new NullLogger())->process($this->input($characterNames), new Post(), ['code' => $code]);
    }

    private function processorPublishingThrough(LoggerInterface $logger): JoinMiningOperationProcessor
    {
        return new JoinMiningOperationProcessor(
            $this->security,
            $this->operationRepositoryReadingOperations(),
            new MiningOperationEnrolment($this->characterRepositoryReadingOpenEnrolments(), $this->entityManager),
            new MiningOperationResourceMapper(),
            new MercurePublisherService($this->hub, $logger),
        );
    }

    private function input(array $characterNames): JoinMiningOperationInput
    {
        $input = new JoinMiningOperationInput();
        $input->characterIds = $this->idsOf($characterNames);

        return $input;
    }
}
