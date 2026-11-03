<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\MiningOperation;

use App\Entity\MiningOperationMember;
use App\Entity\User;
use App\Enum\MiningOperationMemberRole;
use App\Enum\MiningOperationMemberStatus;
use App\Enum\MiningOperationStatus;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Uid\Uuid;

#[Group('issue-265')]
final class MiningOperationSettlementApiTest extends WebTestCase
{
    use ClockSensitiveTrait;
    use DrivesTheE1MiningOperation;

    private const string PAID_AT = '2026-11-05T22:24:00+00:00';
    private const string SETTLED_AT = '2026-11-05T23:35:00+00:00';

    protected function setUp(): void
    {
        $this->setUpTheE1Pilots();
    }

    public function testFreezingTheSharesCreatesThePaymentsTowardsTheBoosterThenTheCorporation(): void
    {
        $this->g1();
        $this->as($this->brann);

        $this->request('GET', $this->paymentsUri());

        self::assertSame(
            [
                [0, 'booster_cut', 'Aria Solberg', 'Cyra Lune', 404_864, false, null, null],
                [1, 'booster_cut', 'Brann Holt', 'Cyra Lune', 430_269, false, null, null],
                [2, 'collected_corporation_cut', 'Cyra Lune', self::CORPORATION_NAME, 278_386, false, null, null],
            ],
            array_map(
                fn (array $payment): array => $this->fields($payment, ['position', 'kind', 'payerName', 'beneficiaryName', 'amount', 'paid', 'paidAt', 'paidByName']),
                $this->responseJson(),
            ),
        );
    }

    public function testOperationWhoseSharesAreNotFrozenHasNoPayment(): void
    {
        $this->closingE1();
        $this->as($this->aria);

        $this->request('GET', $this->paymentsUri());

        self::assertSame([200, []], [$this->client->getResponse()->getStatusCode(), $this->responseJson()]);
    }

    public function testOwnerMarksAPaymentPaidWithHerNameAndTheClockTime(): void
    {
        $this->g1();

        $this->markAs($this->aria, 0, true, self::PAID_AT);

        self::assertSame(
            [200, true, self::PAID_AT, 'Aria Solberg'],
            [$this->client->getResponse()->getStatusCode(), ...$this->fields($this->responseJson(), ['paid', 'paidAt', 'paidByName'])],
        );
    }

    public function testMarkingAPaidPaymentPaidAgainKeepsItsFirstTrace(): void
    {
        $this->g1();
        $this->markAs($this->aria, 0, true, self::PAID_AT);

        $this->markAs($this->aria, 0, true, '2026-11-05T22:40:00+00:00');

        self::assertSame([200, self::PAID_AT], [$this->client->getResponse()->getStatusCode(), $this->responseJson()['paidAt'] ?? null]);
    }

    public function testUnmarkingAPaymentClearsItsTrace(): void
    {
        $this->g1();
        $this->markAs($this->aria, 0, true, self::PAID_AT);
        $marked = $this->fields($this->responseJson(), ['paid', 'paidByName']);

        $this->markAs($this->aria, 0, false, '2026-11-05T22:30:00+00:00');

        self::assertSame(
            ['marked' => [true, 'Aria Solberg'], 'unmarked' => [200, false, null, null]],
            ['marked' => $marked, 'unmarked' => [$this->client->getResponse()->getStatusCode(), ...$this->fields($this->responseJson(), ['paid', 'paidAt', 'paidByName'])]],
        );
    }

    public function testDebtorMarksHisOwnPaymentPaid(): void
    {
        $this->g1();

        $this->markAs($this->brann, 1, true, self::PAID_AT);

        self::assertSame([200, true, 'Brann Holt'], [$this->client->getResponse()->getStatusCode(), ...$this->fields($this->responseJson(), ['paid', 'paidByName'])]);
    }

    public function testPlainMemberCannotMarkThePaymentOfAnotherParticipantWhichStaysUnpaid(): void
    {
        $this->g1();

        $this->markAs($this->brann, 0, true, self::PAID_AT);
        $refusal = $this->errorDetail();

        self::assertSame(
            [[403, 'Only the payer, the operation owner or an admin can mark this payment'], false],
            [$refusal, $this->paymentsSeenBy($this->aria)[0]['paid'] ?? null],
        );
    }

    public function testAdminMarksThePaymentOfAnotherParticipant(): void
    {
        $this->g1();
        $this->cyraMembership = $this->membershipNamed('Cyra Lune')->setRole(MiningOperationMemberRole::Admin);
        $this->em->flush();

        $this->markAs($this->cyra, 1, true, self::PAID_AT);

        self::assertSame([200, 'Cyra Lune'], [$this->client->getResponse()->getStatusCode(), $this->responseJson()['paidByName'] ?? null]);
    }

    public function testMissingOrInvalidPaidFlagIsAViolationOnPaid(): void
    {
        $this->g1();
        $paymentUri = $this->paymentUri($this->paymentIdAt(0));

        $outcomes = [];
        foreach ([['paid' => null], [], ['paid' => 'yes']] as $body) {
            $this->as($this->aria);
            $this->request('PATCH', $paymentUri, $body);
            $outcomes[] = [$this->client->getResponse()->getStatusCode(), $this->violatedPaths()];
        }

        self::assertSame([[422, ['paid']], [422, ['paid']], [422, ['paid']]], $outcomes);
    }

    public function testPaymentOfAnotherOperationUnknownOrNotAUuidIsNotFound(): void
    {
        $otherOperation = $this->persistMiningOperation($this->em, $this->aria, 'Ochre belt — samedi', '2026-11-07T18:00:00+00:00');
        $this->em->flush();
        $this->g1();
        $foreignPaymentId = $this->paymentIdAt(0);

        $outcomes = [];
        foreach ([
            sprintf('/api/mining-operations/%s/payments/%s', $otherOperation->getId(), $foreignPaymentId),
            $this->paymentUri(Uuid::v4()->toRfc4122()),
            $this->paymentUri('abc'),
        ] as $uri) {
            $this->as($this->aria);
            $this->request('PATCH', $uri, ['paid' => true]);
            $outcomes[] = $this->errorDetail();
        }

        self::assertSame([[404, 'Payment not found'], [404, 'Payment not found'], [404, 'Payment not found']], $outcomes);
    }

    public function testPaymentOfASettledOperationCannotBeMarked(): void
    {
        $this->g1();
        $paymentId = $this->paymentIdAt(0);
        $this->reloadedOperation()->setStatus(MiningOperationStatus::Settled);
        $this->em->flush();
        $this->as($this->aria);

        $this->request('PATCH', $this->paymentUri($paymentId), ['paid' => true]);

        self::assertSame([400, 'Payments can only be marked while the shares are frozen'], $this->errorDetail());
    }

    public function testPilotWithoutAccessCannotMarkAPayment(): void
    {
        $this->g1();

        $this->markAs($this->dorn, 0, true, self::PAID_AT);

        self::assertSame([403, 'You do not have access to this operation'], $this->errorDetail());
    }

    public function testOperationWithAnUnpaidPaymentCannotBeSettledAndStaysInItsShares(): void
    {
        $this->g1();
        $this->markAs($this->aria, 0, true, self::PAID_AT);
        $this->markAs($this->aria, 1, true, self::PAID_AT);
        $this->as($this->aria);

        $this->post('settle');

        self::assertSame(
            [[400, 'Every payment must be marked paid before settling: 1 left'], MiningOperationStatus::Shares],
            [$this->errorDetail(), $this->reloadedOperation()->getStatus()],
        );
    }

    public function testOwnerSettlesTheOperationOnceEveryPaymentIsPaid(): void
    {
        $this->g1();
        foreach ([0, 1, 2] as $position) {
            $this->markAs($this->aria, $position, true, self::PAID_AT);
        }
        self::mockTime(new \DateTimeImmutable(self::SETTLED_AT));
        $this->as($this->aria);

        $this->post('settle');

        self::assertSame(
            [200, 'settled', self::SETTLED_AT, 'Aria Solberg'],
            [$this->client->getResponse()->getStatusCode(), ...$this->fields($this->responseJson(), ['status', 'archivedAt', 'archivedByName'])],
        );
    }

    public function testOperationWithoutAnyPaymentIsSettledAtOnce(): void
    {
        $this->closingE1();
        $this->updateValuation(['valuationSide' => 'sell']);
        $this->as($this->aria);
        $this->post('freeze-shares');
        $this->as($this->aria);

        $this->post('settle');

        self::assertSame([200, 'settled'], [$this->client->getResponse()->getStatusCode(), $this->responseJson()['status'] ?? null]);
    }

    public function testOnlyAnOperationWithFrozenSharesCanBeSettled(): void
    {
        $this->closingE1();
        $this->as($this->aria);
        $this->post('settle');
        $closing = $this->errorDetail();
        $this->reloadedOperation()->setStatus(MiningOperationStatus::Settled);
        $this->em->flush();
        $this->as($this->aria);
        $this->post('settle');

        self::assertSame(
            [[400, 'Only an operation with frozen shares can be settled'], [400, 'Only an operation with frozen shares can be settled']],
            [$closing, $this->errorDetail()],
        );
    }

    public function testPlainMemberCannotSettleTheOperation(): void
    {
        $this->g1();
        $this->as($this->brann);

        $this->post('settle');

        self::assertSame([403, 'Only the operation owner or an admin can perform this action'], $this->errorDetail());
    }

    public function testArchivedOperationRefusesAnyRoleChangeRemovalOrWithdrawal(): void
    {
        $this->addMiningMembership($this->spodumain, $this->elia, MiningOperationMemberRole::Member, MiningOperationMemberStatus::Pending, ['Elia Varr']);
        $this->em->flush();
        $this->g1();
        $this->reloadedOperation()->setStatus(MiningOperationStatus::Settled);
        $this->em->flush();

        $outcomes = [];
        $this->as($this->aria);
        $this->request('PATCH', $this->memberUri('Brann Holt'), ['role' => 'admin']);
        $outcomes[] = $this->errorDetail();
        $this->as($this->aria);
        $this->request('DELETE', $this->memberUri('Elia Varr'));
        $outcomes[] = $this->errorDetail();
        $this->as($this->elia);
        $this->post('leave');
        $outcomes[] = $this->errorDetail();

        self::assertSame(
            [
                'outcomes' => [[400, 'This operation is archived'], [400, 'This operation is archived'], [400, 'This operation is archived']],
                'brannRole' => MiningOperationMemberRole::Member,
                'eliaStatus' => MiningOperationMemberStatus::Pending,
            ],
            [
                'outcomes' => $outcomes,
                'brannRole' => $this->reloadedMembership('Brann Holt')->getRole(),
                'eliaStatus' => $this->reloadedMembership('Elia Varr')->getStatus(),
            ],
        );
    }

    public function testArchivedOperationKeepsTheMessagesOfTheWritesThatAlreadyCheckTheStatus(): void
    {
        $this->g1();
        $this->reloadedOperation()->setStatus(MiningOperationStatus::Settled);
        $this->em->flush();

        $outcomes = [];
        $this->as($this->aria);
        $this->request('PATCH', sprintf('/api/mining-operations/%s/valuation', $this->spodumain->getId()), ['boosterCutPercent' => 20]);
        $outcomes[] = $this->errorDetail();
        $this->as($this->aria);
        $this->post('freeze-shares');
        $outcomes[] = $this->errorDetail();
        $this->as($this->aria);
        $this->post(sprintf('characters/%s/quantity-corrections', $this->enrolmentIdOf('Brann II')), ['typeId' => self::VELDSPAR, 'quantity' => 40_000]);
        $outcomes[] = $this->errorDetail();
        $this->as($this->aria);
        $this->post(sprintf('characters/%s/snapshot-retry', $this->enrolmentIdOf('Brann II')));
        $outcomes[] = $this->errorDetail();
        $this->as($this->aria);
        $this->post('join-code');
        $outcomes[] = $this->errorDetail();
        $this->as($this->aria);
        $this->request('DELETE', sprintf('/api/mining-operations/%s', $this->spodumain->getId()));
        $outcomes[] = $this->errorDetail();

        self::assertSame(
            [
                [400, 'The shares of this operation are frozen'],
                [400, 'Only a closing operation can have its shares frozen'],
                [400, 'Quantities can only be corrected while the operation is closing'],
                [400, 'This snapshot cannot be retried'],
                [400, 'This operation is no longer accepting participants'],
                [400, 'Only a planned operation can be deleted'],
            ],
            $outcomes,
        );
    }

    public function testPlainMemberCannotRetryTheFailedEndSnapshotOfHisCharacterWhichStaysFailed(): void
    {
        $this->closingE1(failedEnds: ['Brann II']);
        $this->as($this->brann);

        $this->post(sprintf('characters/%s/snapshot-retry', $this->enrolmentIdOf('Brann II')));

        self::assertSame(
            [[403, 'Only the operation owner or an admin can retry an end snapshot'], 'failed'],
            [$this->errorDetail(), $this->endSnapshotStatusOf('Brann II')],
        );
    }

    public function testOwnerRetriesTheFailedEndSnapshotOfAnotherPilot(): void
    {
        $this->closingE1(failedEnds: ['Brann II']);
        $this->as($this->aria);

        $this->post(sprintf('characters/%s/snapshot-retry', $this->enrolmentIdOf('Brann II')));

        self::assertSame([204, 'waiting'], [$this->client->getResponse()->getStatusCode(), $this->endSnapshotStatusOf('Brann II')]);
    }

    public function testPlainMemberIsRefusedTheEndSnapshotRetryEvenWhenItIsTaken(): void
    {
        $this->closingE1();
        $this->as($this->brann);

        $this->post(sprintf('characters/%s/snapshot-retry', $this->enrolmentIdOf('Brann II')));

        self::assertSame([403, 'Only the operation owner or an admin can retry an end snapshot'], $this->errorDetail());
    }

    public function testLedgerOfAClosingOperationOffersTheEndSnapshotRetryToTheOwnerOnly(): void
    {
        $this->closingE1(failedEnds: ['Brann II']);

        $this->as($this->brann);
        $this->request('GET', sprintf('/api/mining-operations/%s/ledger', $this->spodumain->getId()));
        $seenByBrann = $this->canRetryOf('Brann II');
        $this->as($this->aria);
        $this->request('GET', sprintf('/api/mining-operations/%s/ledger', $this->spodumain->getId()));
        $seenByAria = $this->canRetryOf('Brann II');

        self::assertSame(['brann' => false, 'aria' => true], ['brann' => $seenByBrann, 'aria' => $seenByAria]);
    }

    private function markAs(User $user, int $position, bool $paid, string $at): void
    {
        $paymentId = $this->paymentIdAt($position);
        self::mockTime(new \DateTimeImmutable($at));
        $this->as($user);
        $this->request('PATCH', $this->paymentUri($paymentId), ['paid' => $paid]);
    }

    private function paymentsSeenBy(User $user): array
    {
        $this->as($user);
        $this->request('GET', $this->paymentsUri());

        return $this->responseJson();
    }

    private function paymentIdAt(int $position): string
    {
        foreach ($this->paymentsSeenBy($this->aria) as $payment) {
            if (($payment['position'] ?? null) === $position) {
                return (string) $payment['id'];
            }
        }

        return Uuid::v4()->toRfc4122();
    }

    private function paymentsUri(): string
    {
        return sprintf('/api/mining-operations/%s/payments', $this->spodumain->getId());
    }

    private function paymentUri(string $paymentId): string
    {
        return sprintf('/api/mining-operations/%s/payments/%s', $this->spodumain->getId(), $paymentId);
    }

    private function memberUri(string $mainCharacterName): string
    {
        return sprintf('/api/mining-operations/%s/members/%s', $this->spodumain->getId(), $this->membershipNamed($mainCharacterName)->getId());
    }

    private function reloadedMembership(string $mainCharacterName): MiningOperationMember
    {
        foreach ($this->reloadedOperation()->getMembers() as $member) {
            if ($member->getUser()->getMainCharacter()?->getName() === $mainCharacterName) {
                return $member;
            }
        }

        throw new \LogicException(sprintf('%s is not a member', $mainCharacterName));
    }

    private function endSnapshotStatusOf(string $characterName): ?string
    {
        $connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();

        $status = $connection->fetchOne(
            "SELECT status FROM mining_ledger_snapshots WHERE operation_character_id::text = ? AND kind = 'end'",
            [$this->enrolmentIdOf($characterName)],
        );

        return $status === false ? null : (string) $status;
    }

    private function canRetryOf(string $characterName): ?bool
    {
        foreach ($this->responseJson()['participants'] ?? [] as $participant) {
            foreach ($participant['characters'] ?? [] as $character) {
                if (($character['name'] ?? null) === $characterName) {
                    return $character['canRetry'] ?? null;
                }
            }
        }

        return null;
    }
}
