<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\MiningOperation;

use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use App\ApiResource\MiningOperation\MiningPaymentResource;
use App\Entity\MiningOperation;
use App\Entity\User;
use App\Enum\MiningOperationMemberRole;
use App\Enum\MiningOperationMemberStatus;
use App\Enum\MiningOperationStatus;
use App\Enum\MiningOperationVisibility;
use App\Service\MiningOperation\MiningOperationAccess;
use App\State\Provider\MiningOperation\MiningOperationResourceMapper;
use App\State\Provider\MiningOperation\MiningPaymentCollectionProvider;
use App\State\Provider\MiningOperation\MiningPaymentItemProvider;
use App\Tests\Unit\Service\Mining\BuildsOreValueServiceOnSdeOres;
use App\Tests\Unit\Service\MiningOperation\BuildsLedgerSnapshots;
use App\Tests\Unit\Service\MiningOperation\BuildsMiningPayments;
use App\Tests\Unit\Service\MiningOperation\BuildsSpodumainOperation;
use App\Tests\Unit\Service\MiningOperation\BuildsValuedSpodumainOperation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

#[CoversClass(MiningPaymentItemProvider::class)]
#[CoversClass(MiningPaymentCollectionProvider::class)]
#[Group('issue-265')]
final class MiningPaymentProvidersTest extends TestCase
{
    use BuildsSpodumainOperation;
    use BuildsLedgerSnapshots;
    use BuildsOreValueServiceOnSdeOres;
    use BuildsValuedSpodumainOperation;
    use BuildsMiningPayments;

    private MiningOperation $operation;

    protected function setUp(): void
    {
        $this->setUpSpodumainPilots();
        $this->operation = $this->g1Operation();
    }

    public function testParticipantReadsThePaymentsInPositionOrder(): void
    {
        self::assertSame(
            [
                [0, 'booster_cut', 'Aria Solberg', 'Cyra Lune', self::G1_ARIA_PAYMENT, false],
                [1, 'booster_cut', 'Brann Holt', 'Cyra Lune', self::G1_BRANN_PAYMENT, false],
                [2, 'collected_corporation_cut', 'Cyra Lune', self::CORPORATION_NAME, self::G1_COLLECTED_CORPORATION_CUT, false],
            ],
            $this->rowsOf($this->paymentsSeenBy($this->brann)),
        );
    }

    public function testCorporationPaymentHasNoBeneficiaryMember(): void
    {
        $payments = $this->paymentsSeenBy($this->aria);

        self::assertSame(
            [$this->membershipOf($this->operation, $this->cyra)?->getId()?->toRfc4122(), null],
            [$payments[2]->payerMemberId ?? 'missing', $payments[2]->beneficiaryMemberId ?? null],
        );
    }

    public function testOperationWhoseSharesAreNotFrozenHasNoPayment(): void
    {
        $operation = $this->withCyraBoostingForTenPercent($this->e1Operation());

        self::assertSame([], $this->paymentsSeenBy($this->aria, $operation));
    }

    public function testCorporationMemberReadsThePaymentsOfAnArchivedOperationOpenToTheCorporation(): void
    {
        $this->operation->setStatus(MiningOperationStatus::Settled);

        self::assertCount(3, $this->paymentsSeenBy($this->elia));
    }

    public function testCorporationMemberCannotReadThePaymentsOfAnOperationStillInItsShares(): void
    {
        $this->expectException(AccessDeniedHttpException::class);
        $this->expectExceptionMessage('You do not have access to this operation');

        $this->paymentsSeenBy($this->elia);
    }

    public function testCorporationMemberCannotReadThePaymentsOfAnArchivedPrivateOperation(): void
    {
        $this->operation->setStatus(MiningOperationStatus::Settled)->setVisibility(MiningOperationVisibility::Private);

        $this->expectException(AccessDeniedHttpException::class);
        $this->expectExceptionMessage('You do not have access to this operation');

        $this->paymentsSeenBy($this->elia);
    }

    public function testDebtorMayMarkHisOwnPayment(): void
    {
        $payment = $this->paymentAt($this->operation, 1);

        $resource = $this->paymentToMarkBy($this->brann, $payment->getId()?->toRfc4122());

        self::assertSame([$payment->getId()?->toRfc4122(), 'Brann Holt'], [$resource->id, $resource->payerName]);
    }

    public function testPlainMemberCannotMarkThePaymentOfAnotherParticipant(): void
    {
        $this->expectException(AccessDeniedHttpException::class);
        $this->expectExceptionMessage('Only the payer, the operation owner or an admin can mark this payment');

        $this->paymentToMarkBy($this->brann, $this->paymentAt($this->operation, 0)->getId()?->toRfc4122());
    }

    public function testAdminMayMarkThePaymentOfAnotherParticipant(): void
    {
        $this->membershipOf($this->operation, $this->cyra)?->setRole(MiningOperationMemberRole::Admin);
        $payment = $this->paymentAt($this->operation, 1);

        self::assertSame($payment->getId()?->toRfc4122(), $this->paymentToMarkBy($this->cyra, $payment->getId()?->toRfc4122())->id);
    }

    public function testPendingMemberCannotMarkAPayment(): void
    {
        $this->addMembership($this->operation, $this->elia, MiningOperationMemberRole::Member, MiningOperationMemberStatus::Pending, ['Elia Varr']);

        $this->expectException(AccessDeniedHttpException::class);
        $this->expectExceptionMessage('You do not have access to this operation');

        $this->paymentToMarkBy($this->elia, $this->paymentAt($this->operation, 0)->getId()?->toRfc4122());
    }

    public function testPilotWithoutAccessCannotMarkAPayment(): void
    {
        $this->expectException(AccessDeniedHttpException::class);
        $this->expectExceptionMessage('You do not have access to this operation');

        $this->paymentToMarkBy($this->dorn, $this->paymentAt($this->operation, 0)->getId()?->toRfc4122());
    }

    public function testPaymentOfAnotherOperationIsNotFound(): void
    {
        $otherOperation = $this->g1Operation();

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('Payment not found');

        $this->paymentToMarkBy($this->aria, $this->paymentAt($otherOperation, 0)->getId()?->toRfc4122());
    }

    public function testUnknownPaymentIsNotFound(): void
    {
        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('Payment not found');

        $this->paymentToMarkBy($this->aria, Uuid::v4()->toRfc4122());
    }

    public function testPaymentIdThatIsNotAUuidIsNotFound(): void
    {
        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('Payment not found');

        $this->paymentToMarkBy($this->aria, self::NOT_A_UUID);
    }

    private function security(User $user): Security
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);

        return $security;
    }

    private function paymentsSeenBy(User $user, ?MiningOperation $operation = null): array
    {
        $provider = new MiningPaymentCollectionProvider(
            $this->security($user),
            $this->operationRepositoryReadingOperations(),
            new MiningOperationAccess(),
            new MiningOperationResourceMapper(),
        );

        return $provider->provide(new GetCollection(), ['operationId' => ($operation ?? $this->operation)->getId()?->toRfc4122()]);
    }

    private function paymentToMarkBy(User $user, ?string $paymentId): MiningPaymentResource
    {
        $provider = new MiningPaymentItemProvider(
            $this->security($user),
            $this->operationRepositoryReadingOperations(),
            new MiningOperationAccess(),
            new MiningOperationResourceMapper(),
        );

        return $provider->provide(new Patch(), ['operationId' => $this->operation->getId()?->toRfc4122(), 'id' => $paymentId]);
    }

    private function rowsOf(array $payments): array
    {
        return array_map(
            static fn (MiningPaymentResource $payment): array => [$payment->position, $payment->kind, $payment->payerName, $payment->beneficiaryName, $payment->amount, $payment->paid],
            $payments,
        );
    }
}
