<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

final readonly class TransferPlanner
{
    private const int MAX_EXACT_PARTICIPANTS = 12;

    /**
     * @param array<string, int> $heldCentsByParticipant
     * @param array<string, int> $dueCentsByParticipant
     */
    public function plan(array $heldCentsByParticipant, array $dueCentsByParticipant): TransferPlan
    {
        $participantIds = array_map('strval', array_unique([...array_keys($heldCentsByParticipant), ...array_keys($dueCentsByParticipant)]));
        sort($participantIds, SORT_STRING);

        $balances = [];
        foreach ($participantIds as $participantId) {
            $balances[$participantId] = ($heldCentsByParticipant[$participantId] ?? 0) - ($dueCentsByParticipant[$participantId] ?? 0);
        }

        $roundingGapCents = array_sum($balances);
        if ($participantIds !== []) {
            $balances[$this->largestDueParticipant($participantIds, $dueCentsByParticipant)] -= $roundingGapCents;
        }

        $unsettled = array_filter($balances, static fn (int $balance): bool => $balance !== 0);
        $groups = count($unsettled) <= self::MAX_EXACT_PARTICIPANTS ? $this->independentGroups($unsettled) : [$unsettled];

        $transfers = [];
        foreach ($groups as $group) {
            array_push($transfers, ...$this->settle($group));
        }

        return new TransferPlan($transfers, $roundingGapCents);
    }

    /**
     * @param list<string> $sortedParticipantIds
     * @param array<string, int> $dueCentsByParticipant
     */
    private function largestDueParticipant(array $sortedParticipantIds, array $dueCentsByParticipant): string
    {
        $largest = $sortedParticipantIds[0];
        foreach ($sortedParticipantIds as $participantId) {
            if (($dueCentsByParticipant[$participantId] ?? 0) > ($dueCentsByParticipant[$largest] ?? 0)) {
                $largest = $participantId;
            }
        }

        return $largest;
    }

    /**
     * @param array<string, int> $balances
     *
     * @return list<array<string, int>>
     */
    private function independentGroups(array $balances): array
    {
        $participantIds = array_keys($balances);
        $values = array_values($balances);
        $count = count($participantIds);
        if ($count === 0) {
            return [];
        }

        $full = (1 << $count) - 1;
        $sum = array_fill(0, $full + 1, 0);
        $groupsUpTo = array_fill(0, $full + 1, 0);
        $removedLast = array_fill(0, $full + 1, -1);

        for ($mask = 1; $mask <= $full; ++$mask) {
            $lowest = 0;
            while ((($mask >> $lowest) & 1) === 0) {
                ++$lowest;
            }
            $sum[$mask] = $sum[$mask ^ (1 << $lowest)] + $values[$lowest];

            $best = -1;
            for ($index = 0; $index < $count; ++$index) {
                if ((($mask >> $index) & 1) === 0) {
                    continue;
                }
                $candidate = $groupsUpTo[$mask ^ (1 << $index)];
                if ($candidate > $best) {
                    $best = $candidate;
                    $removedLast[$mask] = $index;
                }
            }
            $groupsUpTo[$mask] = $best + ($sum[$mask] === 0 ? 1 : 0);
        }

        $order = [];
        for ($mask = $full; $mask > 0; $mask ^= 1 << $removedLast[$mask]) {
            $order[] = $removedLast[$mask];
        }

        $groups = [];
        $current = [];
        $prefix = 0;
        foreach (array_reverse($order) as $index) {
            $current[$participantIds[$index]] = $values[$index];
            $prefix |= 1 << $index;
            if ($sum[$prefix] === 0) {
                $groups[] = $current;
                $current = [];
            }
        }

        return $groups;
    }

    /**
     * @param array<string, int> $balances
     *
     * @return list<PlannedTransfer>
     */
    private function settle(array $balances): array
    {
        $debtors = array_filter($balances, static fn (int $balance): bool => $balance > 0);
        $creditors = array_map(static fn (int $balance): int => -$balance, array_filter($balances, static fn (int $balance): bool => $balance < 0));
        $this->sortLargestFirst($debtors);
        $this->sortLargestFirst($creditors);

        $transfers = [];
        while ($debtors !== [] && $creditors !== []) {
            $debtorId = array_key_first($debtors);
            $creditorId = array_key_first($creditors);
            $amount = min($debtors[$debtorId], $creditors[$creditorId]);
            $transfers[] = new PlannedTransfer((string) $debtorId, (string) $creditorId, $amount);

            $debtors[$debtorId] -= $amount;
            $creditors[$creditorId] -= $amount;
            if ($debtors[$debtorId] === 0) {
                unset($debtors[$debtorId]);
            }
            if ($creditors[$creditorId] === 0) {
                unset($creditors[$creditorId]);
            }
        }

        return $transfers;
    }

    /**
     * @param array<string, int> $amounts
     */
    private function sortLargestFirst(array &$amounts): void
    {
        uksort($amounts, static fn (int|string $a, int|string $b): int => [$amounts[$b], (string) $a] <=> [$amounts[$a], (string) $b]);
    }
}
