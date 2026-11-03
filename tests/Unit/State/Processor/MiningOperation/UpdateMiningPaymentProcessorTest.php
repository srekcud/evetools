<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\MiningOperation;

use ApiPlatform\Metadata\Patch;
use App\ApiResource\Input\MiningOperation\UpdateMiningPaymentInput;
use App\ApiResource\MiningOperation\MiningPaymentResource;
use App\Entity\MiningOperation;
use App\Entity\MiningPayment;
use App\Entity\User;
use App\Enum\MiningOperationMemberRole;
use App\Enum\MiningOperationStatus;
use App\Service\Mercure\MercurePublisherService;
use App\Service\MiningOperation\MiningOperationSettlement;
use App\State\Processor\MiningOperation\UpdateMiningPaymentProcessor;
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
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Mercure\Exception\RuntimeException;
use Symfony\Component\Mercure\HubInterface;

#[CoversClass(UpdateMiningPaymentProcessor::class)]
#[CoversClass(MiningOperationSettlement::class)]
#[CoversClass(MiningPayment::class)]
#[Group('issue-265')]
#[AllowMockObjectsWithoutExpectations]
final class UpdateMiningPaymentProcessorTest extends TestCase
{
    use BuildsSpodumainOperation;
    use BuildsLedgerSnapshots;
    use BuildsOreValueServiceOnSdeOres;
    use BuildsValuedSpodumainOperation;
    use BuildsMiningPayments;

    private Security&Stub $security;
    private EntityManagerInterface&MockObject $entityManager;
    private HubInterface&MockObject $hub;
    private MockClock $clock;
    private MiningOperation $operation;

    protected function setUp(): void
    {
        $this->setUpSpodumainPilots();
        $this->operation = $this->g1Operation();
        $this->security = $this->createStub(Security::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->hub = $this->createMock(HubInterface::class);
        $this->clock = new MockClock(self::PAID_AT);
    }

    public function testOwnerMarksTheBoosterCutOfAriaPaidAndEveryParticipantIsNotified(): void
    {
        $this->actAs($this->aria);
        $publishedUpdates = $this->capturePublishedUpdates($this->hub);
        $this->entityManager->expects($this->once())->method('flush');
        $payment = $this->paymentAt($this->operation, 0);

        $resource = $this->mark($payment, true);

        self::assertSame(
            [
                'id' => $payment->getId()?->toRfc4122(),
                'position' => 0,
                'kind' => 'booster_cut',
                'payerMemberId' => $this->membershipOf($this->operation, $this->aria)?->getId()?->toRfc4122(),
                'payerName' => 'Aria Solberg',
                'beneficiaryMemberId' => $this->membershipOf($this->operation, $this->cyra)?->getId()?->toRfc4122(),
                'beneficiaryName' => 'Cyra Lune',
                'amount' => self::G1_ARIA_PAYMENT,
                'paid' => true,
                'paidAt' => self::PAID_AT,
                'paidByName' => 'Aria Solberg',
            ],
            $this->resourceToArray($resource),
        );
        self::assertSame(
            ['paid' => true, 'paidAt' => self::PAID_AT, 'paidBy' => 'Aria Solberg', 'paidByName' => 'Aria Solberg'],
            $this->paymentStateOf($payment),
        );
        self::assertSame(
            $this->expectedMessages([$this->aria, $this->brann, $this->cyra], $this->operation, 'payment_updated', [
                'paymentId' => $payment->getId()?->toRfc4122(),
                'paid' => true,
            ]),
            $this->publishedMessages($publishedUpdates),
        );
    }

    public function testMarkingAPaidPaymentPaidAgainKeepsItsFirstTrace(): void
    {
        $this->actAs($this->aria);
        $payment = $this->paymentAt($this->operation, 0);
        $this->mark($payment, true);
        $this->clock->modify('2026-11-05T22:40:00+00:00');

        $resource = $this->mark($payment, true);

        self::assertSame(
            [self::PAID_AT, ['paid' => true, 'paidAt' => self::PAID_AT, 'paidBy' => 'Aria Solberg', 'paidByName' => 'Aria Solberg']],
            [$resource->paidAt?->format(\DateTimeInterface::ATOM), $this->paymentStateOf($payment)],
        );
    }

    public function testUnmarkingAPaidPaymentClearsItsTrace(): void
    {
        $this->actAs($this->aria);
        $payment = $this->paymentAt($this->operation, 0);
        $markedPaid = $this->mark($payment, true)->paid;

        $resource = $this->mark($payment, false);

        self::assertSame(
            [true, [false, null, null], ['paid' => false, 'paidAt' => null, 'paidBy' => null, 'paidByName' => null]],
            [$markedPaid, [$resource->paid, $resource->paidAt, $resource->paidByName], $this->paymentStateOf($payment)],
        );
    }

    public function testPlainMemberMarksHisOwnPaymentPaid(): void
    {
        $this->actAs($this->brann);
        $payment = $this->paymentAt($this->operation, 1);

        $resource = $this->mark($payment, true);

        self::assertSame(
            [true, 'Brann Holt', ['paid' => true, 'paidAt' => self::PAID_AT, 'paidBy' => 'Brann Holt', 'paidByName' => 'Brann Holt']],
            [$resource->paid, $resource->paidByName, $this->paymentStateOf($payment)],
        );
    }

    public function testAdminMarksThePaymentOfAnotherParticipantPaid(): void
    {
        $this->membershipOf($this->operation, $this->cyra)?->setRole(MiningOperationMemberRole::Admin);
        $this->actAs($this->cyra);

        $resource = $this->mark($this->paymentAt($this->operation, 1), true);

        self::assertSame([true, 'Cyra Lune'], [$resource->paid, $resource->paidByName]);
    }

    public function testPaymentOfASettledOperationCannotBeMarked(): void
    {
        $this->operation->setStatus(MiningOperationStatus::Settled);
        $this->actAs($this->aria);
        $this->entityManager->expects($this->never())->method('flush');
        $this->hub->expects($this->never())->method('publish');
        $payment = $this->paymentAt($this->operation, 0);

        try {
            $this->mark($payment, true);
            self::fail('The marking was not refused');
        } catch (BadRequestHttpException $refusal) {
            self::assertSame(
                ['Payments can only be marked while the shares are frozen', false],
                [$refusal->getMessage(), $payment->isPaid()],
            );
        }
    }

    public function testPaymentStaysMarkedWhenTheMercureHubIsDownAndTheFailureIsLogged(): void
    {
        $this->actAs($this->aria);
        $this->hub->method('publish')->willThrowException(new RuntimeException('Mercure hub unreachable'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->exactly(3))->method('warning')->with('Failed to publish mining operation Mercure update');

        $resource = $this->mark($this->paymentAt($this->operation, 0), true, $logger);

        self::assertTrue($resource->paid);
    }

    private function actAs(User $user): void
    {
        $this->security->method('getUser')->willReturn($user);
    }

    private function mark(MiningPayment $payment, bool $paid, ?LoggerInterface $logger = null): MiningPaymentResource
    {
        $input = new UpdateMiningPaymentInput();
        $input->paid = $paid;
        $processor = new UpdateMiningPaymentProcessor(
            $this->security,
            $this->operationRepositoryReadingOperations(),
            new MiningOperationSettlement($this->entityManager, $this->clock),
            new MiningOperationResourceMapper(),
            new MercurePublisherService($this->hub, $logger ?? new NullLogger()),
        );

        return $processor->process($input, new Patch(), [
            'operationId' => $this->operation->getId()?->toRfc4122(),
            'id' => $payment->getId()?->toRfc4122(),
        ]);
    }

    private function resourceToArray(MiningPaymentResource $resource): array
    {
        return [
            'id' => $resource->id,
            'position' => $resource->position,
            'kind' => $resource->kind,
            'payerMemberId' => $resource->payerMemberId,
            'payerName' => $resource->payerName,
            'beneficiaryMemberId' => $resource->beneficiaryMemberId,
            'beneficiaryName' => $resource->beneficiaryName,
            'amount' => $resource->amount,
            'paid' => $resource->paid,
            'paidAt' => $resource->paidAt?->format(\DateTimeInterface::ATOM),
            'paidByName' => $resource->paidByName,
        ];
    }
}
