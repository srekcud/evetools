<?php

declare(strict_types=1);

namespace App\Service\MiningOperation;

use App\Entity\MiningOperation;
use App\Entity\MiningOperationMember;
use App\Entity\MiningPayment;
use App\Entity\Notification;
use App\Entity\User;
use App\Enum\MiningLedgerSnapshotKind;
use App\Enum\MiningLedgerSnapshotStatus;
use App\Enum\MiningOperationMemberRole;
use App\Enum\MiningOperationMemberStatus;
use App\Service\Notification\NotificationDispatcher;

final readonly class MiningOperationNotifier
{
    private const string LEDGER_READY_TITLE = 'Mining ledger ready';
    private const string SHARES_COMPUTED_TITLE = 'Mining shares computed';
    private const string PAYMENT_RECEIVED_TITLE = 'Payment received';
    private const string JOIN_REQUEST_TITLE = 'Mining operation join request';
    private const string ROUTE_TEMPLATE = '/mining/operations/%s';

    public function __construct(private NotificationDispatcher $dispatcher)
    {
    }

    public function endLedgerSettled(MiningOperation $operation): void
    {
        $unreadLedgers = 0;
        $endSnapshots = 0;
        foreach ($operation->getMembers() as $member) {
            foreach ($member->getCharacters() as $operationCharacter) {
                $snapshot = $operationCharacter->snapshotOf(MiningLedgerSnapshotKind::End);
                if ($snapshot === null) {
                    continue;
                }
                if (!$snapshot->getStatus()->isSettled()) {
                    return;
                }
                ++$endSnapshots;
                if ($snapshot->getStatus() !== MiningLedgerSnapshotStatus::Taken) {
                    ++$unreadLedgers;
                }
            }
        }
        if ($endSnapshots === 0) {
            return;
        }

        $level = $unreadLedgers === 0 ? Notification::LEVEL_SUCCESS : Notification::LEVEL_WARNING;
        $message = $unreadLedgers === 0
            ? sprintf('The mining ledger of "%s" is ready: review the mined quantities.', $operation->getLabel())
            : sprintf('The mining ledger of "%s" is ready, but %d ledger(s) could not be read: review the mined quantities.', $operation->getLabel(), $unreadLedgers);

        foreach ($operation->participantUsers() as $user) {
            $this->notify($user, $operation, $level, self::LEDGER_READY_TITLE, $message);
        }
    }

    public function sharesFrozen(MiningOperation $operation): void
    {
        foreach ($operation->participantUsers() as $user) {
            $toPay = 0.0;
            $toReceive = 0.0;
            foreach ($operation->getPayments() as $payment) {
                if ($payment->getPayerMember()?->getUser() === $user) {
                    $toPay += $payment->getAmount();
                }
                if ($payment->getBeneficiaryMember()?->getUser() === $user) {
                    $toReceive += $payment->getAmount();
                }
            }

            $this->notify(
                $user,
                $operation,
                $toPay > 0 ? Notification::LEVEL_WARNING : Notification::LEVEL_INFO,
                self::SHARES_COMPUTED_TITLE,
                $this->sharesMessage($operation, $toPay, $toReceive),
            );
        }
    }

    public function paymentMarkedPaid(MiningPayment $payment, User $markedBy): void
    {
        $beneficiary = $payment->getBeneficiaryMember()?->getUser();
        if ($beneficiary === null || $beneficiary === $markedBy) {
            return;
        }

        $this->notify(
            $beneficiary,
            $payment->getOperation(),
            Notification::LEVEL_SUCCESS,
            self::PAYMENT_RECEIVED_TITLE,
            sprintf(
                '%s paid you %s for "%s".',
                $payment->getPayerName() ?? throw new \LogicException('A payment is owed by a named participant'),
                $this->iskOf($payment->getAmount()),
                $payment->getOperation()->getLabel(),
            ),
        );
    }

    public function joinRequested(MiningOperation $operation, MiningOperationMember $member): void
    {
        if ($member->getStatus() !== MiningOperationMemberStatus::Pending) {
            return;
        }

        $requester = $member->getUser();
        $requesterNames = [];
        foreach ($member->getCharacters() as $operationCharacter) {
            $requesterNames[] = $operationCharacter->getCharacter()->getName();
        }
        $message = sprintf('%s asked to join "%s".', implode(', ', $requesterNames), $operation->getLabel());

        foreach ($this->administratorsOf($operation) as $administrator) {
            if ($administrator !== $requester) {
                $this->notify($administrator, $operation, Notification::LEVEL_INFO, self::JOIN_REQUEST_TITLE, $message);
            }
        }
    }

    /**
     * @return list<User>
     */
    private function administratorsOf(MiningOperation $operation): array
    {
        $administrators = [spl_object_id($operation->getOwner()) => $operation->getOwner()];
        foreach ($operation->acceptedMembers() as $member) {
            if ($member->getRole() === MiningOperationMemberRole::Admin) {
                $administrators[spl_object_id($member->getUser())] = $member->getUser();
            }
        }

        return array_values($administrators);
    }

    private function sharesMessage(MiningOperation $operation, float $toPay, float $toReceive): string
    {
        $parts = [];
        if ($toPay > 0) {
            $parts[] = sprintf('you have to pay %s', $this->iskOf($toPay));
        }
        if ($toReceive > 0) {
            $parts[] = sprintf('you will receive %s', $this->iskOf($toReceive));
        }
        if ($parts === []) {
            return sprintf('The shares of "%s" are computed: you have nothing to pay or receive.', $operation->getLabel());
        }

        return sprintf('The shares of "%s" are computed: %s.', $operation->getLabel(), implode(' and ', $parts));
    }

    private function iskOf(float $amount): string
    {
        return number_format(round($amount), 0, '.', ' ') . ' ISK';
    }

    private function notify(User $user, MiningOperation $operation, string $level, string $title, string $message): void
    {
        $operationId = PersistedUuid::of($operation->getId());

        $this->dispatcher->dispatch(
            $user,
            Notification::CATEGORY_MINING_OPERATION,
            $level,
            $title,
            $message,
            ['operationId' => $operationId],
            sprintf(self::ROUTE_TEMPLATE, $operationId),
        );
    }
}
