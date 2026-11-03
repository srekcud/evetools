<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\MiningOperation;

use App\Entity\MiningOperation;
use App\Entity\Notification;
use App\Enum\MiningLedgerSnapshotStatus;
use App\Enum\MiningOperationMemberRole;
use App\Enum\MiningOperationMemberStatus;
use App\Service\MiningOperation\MiningOperationNotifier;
use App\Tests\Unit\Service\Mining\BuildsOreValueServiceOnSdeOres;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(MiningOperationNotifier::class)]
#[Group('issue-271')]
final class MiningOperationNotifierTest extends TestCase
{
    use BuildsSpodumainOperation;
    use BuildsLedgerSnapshots;
    use BuildsOreValueServiceOnSdeOres;
    use BuildsValuedSpodumainOperation;
    use BuildsMiningPayments;
    use RecordsMiningOperationNotifications;

    private const string LEDGER_READY = 'Mining ledger ready';
    private const string SHARES_COMPUTED = 'Mining shares computed';
    private const string PAYMENT_RECEIVED = 'Payment received';
    private const string JOIN_REQUEST = 'Mining operation join request';
    private const int ARIA_AND_BRANN_PAYMENTS_TO_CYRA = 835_133;

    private MiningOperationNotifier $notifier;

    protected function setUp(): void
    {
        $this->setUpSpodumainPilots();
        $this->notifier = $this->notifierRecording();
    }

    public function testEndLedgerSettledSendsNothingWhenTheOperationHasNoEndSnapshot(): void
    {
        $operation = $this->startedSpodumainOperation();

        $this->notifier->endLedgerSettled($operation);

        self::assertSame([], $this->dispatchedNotifications->getArrayCopy());
    }

    public function testEndLedgerSettledSendsNothingWhileAnEndSnapshotIsStillWaiting(): void
    {
        $operation = $this->startedSpodumainOperation();
        $operation->end(new \DateTimeImmutable(self::ENDED_AT));
        $this->takenEndSnapshot('Brann Holt', [$this->entry('2026-11-05', self::SPODUMAIN, self::S1, 41_000)]);
        $this->waitingEndSnapshot('Brann II', '2026-11-05T21:59:00+00:00');

        $this->notifier->endLedgerSettled($operation);

        self::assertSame([], $this->dispatchedNotifications->getArrayCopy());
    }

    public function testEndLedgerSettledTellsOnlyTheParticipantsThatTheLedgerIsReadyWhenEveryEndSnapshotIsTaken(): void
    {
        $operation = $this->startedSpodumainOperation();
        $this->addMembership($operation, $this->elia, MiningOperationMemberRole::Member, MiningOperationMemberStatus::Pending, ['Elia Varr']);
        $this->addMembership($operation, $this->cyra, MiningOperationMemberRole::Member, MiningOperationMemberStatus::Removed, ['Cyra Lune']);
        $operation->end(new \DateTimeImmutable(self::ENDED_AT));
        $this->takenEndSnapshot('Aria Solberg', [$this->entry('2026-11-05', self::SPODUMAIN, self::S1, 105_000)]);
        $this->takenEndSnapshot('Brann Holt', [$this->entry('2026-11-05', self::SPODUMAIN, self::S1, 41_000)]);
        $this->takenEndSnapshot('Brann II', [$this->entry('2026-11-05', self::BISTOT, self::S2, 7_300)]);

        $this->notifier->endLedgerSettled($operation);

        self::assertSame(['Aria Solberg', 'Brann Holt'], $this->recipientsTitled(self::LEDGER_READY));
        self::assertSame(2, $this->dispatchedNotifications->count());
        $brann = $this->notificationTitled(self::LEDGER_READY, 'Brann Holt');
        self::assertSame(
            [Notification::CATEGORY_MINING_OPERATION, 'success', $this->operationRouteOf($operation), $operation->getId()->toRfc4122()],
            [$brann['category'], $brann['level'], $brann['route'], $brann['data']['operationId'] ?? null],
        );
    }

    public function testEndLedgerSettledWarnsWithTheCountOfCharactersWhoseLedgerCouldNotBeRead(): void
    {
        $operation = $this->startedSpodumainOperation();
        $operation->end(new \DateTimeImmutable(self::ENDED_AT));
        $this->takenEndSnapshot('Aria Solberg', [$this->entry('2026-11-05', self::SPODUMAIN, self::S1, 105_000)]);
        $this->failedEndSnapshot('Brann Holt', MiningLedgerSnapshotStatus::ReconnectRequired);
        $this->failedEndSnapshot('Brann II', MiningLedgerSnapshotStatus::Failed);

        $this->notifier->endLedgerSettled($operation);

        self::assertSame(['Aria Solberg', 'Brann Holt'], $this->recipientsTitled(self::LEDGER_READY));
        $aria = $this->notificationTitled(self::LEDGER_READY, 'Aria Solberg');
        self::assertSame('warning', $aria['level']);
        self::assertMatchesRegularExpression('/\b2\b/', $aria['message']);
        self::assertDoesNotMatchRegularExpression('/\b3\b/', $aria['message']);
    }

    public function testSharesFrozenNotifiesEachParticipantOnceAndNeitherThePendingMemberNorTheCorporation(): void
    {
        $operation = $this->g1Operation();
        $this->addMembership($operation, $this->elia, MiningOperationMemberRole::Member, MiningOperationMemberStatus::Pending, ['Elia Varr']);

        $this->notifier->sharesFrozen($operation);

        self::assertSame(['Aria Solberg', 'Brann Holt', 'Cyra Lune'], $this->recipientsTitled(self::SHARES_COMPUTED));
        self::assertSame(3, $this->dispatchedNotifications->count());
        $aria = $this->notificationTitled(self::SHARES_COMPUTED, 'Aria Solberg');
        self::assertSame(
            [Notification::CATEGORY_MINING_OPERATION, $this->operationRouteOf($operation), $operation->getId()->toRfc4122()],
            [$aria['category'], $aria['route'], $aria['data']['operationId'] ?? null],
        );
    }

    public function testSharesFrozenWarnsEachPayerWithTheAmountHeHasToPay(): void
    {
        $operation = $this->g1Operation();

        $this->notifier->sharesFrozen($operation);

        self::assertSame(['Aria Solberg', 'Brann Holt', 'Cyra Lune'], $this->recipientsTitled(self::SHARES_COMPUTED));
        $aria = $this->notificationTitled(self::SHARES_COMPUTED, 'Aria Solberg');
        $brann = $this->notificationTitled(self::SHARES_COMPUTED, 'Brann Holt');
        self::assertSame(['warning', 'warning'], [$aria['level'], $brann['level']]);
        self::assertStringContainsString((string) self::G1_ARIA_PAYMENT, $this->digitsOf($aria['message']));
        self::assertStringNotContainsString((string) self::G1_BRANN_PAYMENT, $this->digitsOf($aria['message']));
        self::assertStringContainsString((string) self::G1_BRANN_PAYMENT, $this->digitsOf($brann['message']));
    }

    public function testSharesFrozenTellsAPayerWhoIsAlsoABeneficiaryBothTotals(): void
    {
        $operation = $this->g1Operation();

        $this->notifier->sharesFrozen($operation);

        self::assertSame(['Aria Solberg', 'Brann Holt', 'Cyra Lune'], $this->recipientsTitled(self::SHARES_COMPUTED));
        $cyra = $this->notificationTitled(self::SHARES_COMPUTED, 'Cyra Lune');
        self::assertSame('warning', $cyra['level']);
        self::assertStringContainsString((string) self::G1_COLLECTED_CORPORATION_CUT, $this->digitsOf($cyra['message']));
        self::assertStringContainsString((string) self::ARIA_AND_BRANN_PAYMENTS_TO_CYRA, $this->digitsOf($cyra['message']));
    }

    public function testSharesFrozenTellsABeneficiaryWithNothingToPayTheAmountHeReceivesWithoutWarning(): void
    {
        $operation = $this->g1Operation();
        $operation->getPayments()->removeElement($this->paymentAt($operation, 2));

        $this->notifier->sharesFrozen($operation);

        self::assertSame(['Aria Solberg', 'Brann Holt', 'Cyra Lune'], $this->recipientsTitled(self::SHARES_COMPUTED));
        $cyra = $this->notificationTitled(self::SHARES_COMPUTED, 'Cyra Lune');
        self::assertSame('info', $cyra['level']);
        self::assertStringContainsString((string) self::ARIA_AND_BRANN_PAYMENTS_TO_CYRA, $this->digitsOf($cyra['message']));
        self::assertSame('warning', $this->notificationTitled(self::SHARES_COMPUTED, 'Aria Solberg')['level']);
    }

    public function testSharesFrozenWithoutAnyPaymentTellsEveryParticipantThereIsNothingToPay(): void
    {
        $operation = $this->g1Operation();
        $operation->getPayments()->clear();

        $this->notifier->sharesFrozen($operation);

        $notifications = $this->notificationsTitled(self::SHARES_COMPUTED);
        self::assertSame(['Aria Solberg', 'Brann Holt', 'Cyra Lune'], array_column($notifications, 'recipient'));
        self::assertSame(['info', 'info', 'info'], array_column($notifications, 'level'));
        self::assertStringNotContainsString((string) self::G1_ARIA_PAYMENT, $this->digitsOf($notifications[0]['message']));
    }

    public function testPaymentMarkedPaidTellsTheBeneficiaryWhoPaidHimAndHowMuch(): void
    {
        $operation = $this->g1Operation();

        $this->notifier->paymentMarkedPaid($this->paymentAt($operation, 0), $this->aria);

        self::assertSame(['Cyra Lune'], $this->recipientsTitled(self::PAYMENT_RECEIVED));
        self::assertSame(1, $this->dispatchedNotifications->count());
        $cyra = $this->notificationTitled(self::PAYMENT_RECEIVED, 'Cyra Lune');
        self::assertSame(
            [Notification::CATEGORY_MINING_OPERATION, 'success', $this->operationRouteOf($operation), $operation->getId()->toRfc4122()],
            [$cyra['category'], $cyra['level'], $cyra['route'], $cyra['data']['operationId'] ?? null],
        );
        self::assertStringContainsString('Aria Solberg', $cyra['message']);
        self::assertStringContainsString((string) self::G1_ARIA_PAYMENT, $this->digitsOf($cyra['message']));
    }

    public function testPaymentMarkedPaidByItsOwnBeneficiaryNotifiesNobody(): void
    {
        $operation = $this->g1Operation();

        $this->notifier->paymentMarkedPaid($this->paymentAt($operation, 1), $this->cyra);

        self::assertSame([], $this->dispatchedNotifications->getArrayCopy());
    }

    public function testPaymentMarkedPaidTowardsTheCorporationNotifiesNobody(): void
    {
        $operation = $this->g1Operation();

        $this->notifier->paymentMarkedPaid($this->paymentAt($operation, 2), $this->aria);

        self::assertSame([], $this->dispatchedNotifications->getArrayCopy());
    }

    public function testJoinRequestedTellsTheOwnerAndTheAcceptedAdminsButNeitherAPlainMemberNorTheRequester(): void
    {
        $operation = $this->spodumainOperation();
        $this->addMembership($operation, $this->brann, MiningOperationMemberRole::Admin, MiningOperationMemberStatus::Accepted, ['Brann Holt']);
        $this->addMembership($operation, $this->cyra, MiningOperationMemberRole::Member, MiningOperationMemberStatus::Accepted, ['Cyra Lune']);
        $requester = $this->addMembership($operation, $this->elia, MiningOperationMemberRole::Member, MiningOperationMemberStatus::Pending, ['Elia Varr']);

        $this->notifier->joinRequested($operation, $requester);

        self::assertSame(['Aria Solberg', 'Brann Holt'], $this->recipientsTitled(self::JOIN_REQUEST));
        self::assertSame(2, $this->dispatchedNotifications->count());
        $aria = $this->notificationTitled(self::JOIN_REQUEST, 'Aria Solberg');
        self::assertSame(
            [Notification::CATEGORY_MINING_OPERATION, 'info', $this->operationRouteOf($operation), $operation->getId()->toRfc4122()],
            [$aria['category'], $aria['level'], $aria['route'], $aria['data']['operationId'] ?? null],
        );
        self::assertStringContainsString('Elia Varr', $aria['message']);
    }

    public function testJoinRequestedDoesNotTellAnAdminWhoIsPendingOrRemoved(): void
    {
        $operation = $this->spodumainOperation();
        $this->addMembership($operation, $this->brann, MiningOperationMemberRole::Admin, MiningOperationMemberStatus::Pending, ['Brann Holt']);
        $this->addMembership($operation, $this->cyra, MiningOperationMemberRole::Admin, MiningOperationMemberStatus::Removed, ['Cyra Lune']);
        $requester = $this->addMembership($operation, $this->elia, MiningOperationMemberRole::Member, MiningOperationMemberStatus::Pending, ['Elia Varr']);

        $this->notifier->joinRequested($operation, $requester);

        self::assertSame(['Aria Solberg'], $this->recipientsTitled(self::JOIN_REQUEST));
        self::assertSame(1, $this->dispatchedNotifications->count());
    }

    public function testJoinRequestedSendsNothingForAMemberWhoIsAlreadyAccepted(): void
    {
        $operation = $this->spodumainOperation();
        $accepted = $this->addMembership($operation, $this->elia, MiningOperationMemberRole::Member, MiningOperationMemberStatus::Accepted, ['Elia Varr']);

        $this->notifier->joinRequested($operation, $accepted);

        self::assertSame([], $this->dispatchedNotifications->getArrayCopy());
    }
}
