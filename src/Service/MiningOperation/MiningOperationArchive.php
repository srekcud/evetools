<?php

declare(strict_types=1);

namespace App\Service\MiningOperation;

use App\Entity\MiningFrozenMinedValue;
use App\Entity\MiningOperation;
use App\Entity\MiningPayment;
use App\Entity\User;
use App\Enum\MiningSplitMode;

final readonly class MiningOperationArchive
{
    public function __construct(
        private MiningShareCalculator $shareCalculator,
    ) {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function summaryOf(MiningOperation $operation, User $viewer): ?array
    {
        $archivedAt = $operation->getArchivedAt();
        if (!$operation->getStatus()->isArchived() || $archivedAt === null) {
            return null;
        }

        $frozenMinedValues = array_values($operation->getFrozenMinedValues()->toArray());
        usort($frozenMinedValues, static fn (MiningFrozenMinedValue $left, MiningFrozenMinedValue $right): int => $left->getPosition() <=> $right->getPosition());
        $split = $this->shareCalculator->split(
            array_map(static fn (MiningFrozenMinedValue $frozenMinedValue): int => (int) $frozenMinedValue->getMinedValue(), $frozenMinedValues),
            $operation->getBoosterCutPercent(),
            $operation->getCorporationCutPercent(),
        );

        $viewerMembership = $operation->membershipOf($viewer);
        $viewerIndex = null;
        foreach ($frozenMinedValues as $index => $frozenMinedValue) {
            if ($viewerMembership !== null && $frozenMinedValue->getMember() === $viewerMembership) {
                $viewerIndex = $index;
            }
        }

        $payments = $operation->getPayments();

        return [
            'archivedAt' => $archivedAt->setTimezone(new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM),
            'archivedByName' => $operation->getArchivedByName(),
            'totalValue' => $split->totalValue,
            'myMinedValue' => $viewerIndex === null ? null : (int) $frozenMinedValues[$viewerIndex]->getMinedValue(),
            'myShare' => $viewerIndex === null || $operation->getSplitMode() === MiningSplitMode::EachForThemselves ? null : $split->shares[$viewerIndex],
            'paymentsCount' => $payments->count(),
            'paidPaymentsCount' => $payments->filter(static fn (MiningPayment $payment): bool => $payment->isPaid())->count(),
        ];
    }
}
