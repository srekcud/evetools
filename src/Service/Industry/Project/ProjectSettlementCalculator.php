<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectContribution;
use App\Entity\IndustryProjectParticipant;
use App\Entity\IndustryProjectSale;
use App\Enum\ContributionStatus;
use App\Enum\ParticipantStatus;
use App\Enum\SaleChannel;
use App\Repository\IndustryProjectSaleRepository;
use App\Repository\IndustryProjectTransferReceiptRepository;

final readonly class ProjectSettlementCalculator
{
    private const float PERCENT = 100.0;
    private const int CENTS_PER_ISK = 100;
    private const float NO_FEE_FACTOR = 1.0;

    public function __construct(
        private ProjectDistributionService $distributionService,
        private IndustryProjectSaleRepository $saleRepository,
        private IndustryProjectTransferReceiptRepository $receiptRepository,
        private TransferPlanner $transferPlanner,
        private UnsoldProductsOfProject $unsoldProducts,
        private ContractSaleFees $contractSaleFees,
        private TransferJournalVerifier $journalVerifier,
    ) {
    }

    public function calculate(IndustryProject $project): ProjectSettlement
    {
        $sales = $this->saleRepository->findBy(['project' => $project]);
        $distribution = $this->distributionService->calculateDistribution($project);
        $unsoldProducts = $this->unsoldProducts->of($project, $sales);

        $contractFeesByContractId = $this->contractSaleFees->knownFeesByContractId($sales);
        $reason = $this->unavailableReason($project, $sales, $unsoldProducts, $distribution, $contractFeesByContractId);
        $heldCents = $reason === null ? $this->heldCentsByParticipant($project, $sales, $this->marketNetFactor($project), $contractFeesByContractId) : null;
        if ($reason === null && $heldCents === null) {
            $reason = TransfersUnavailableReason::UnknownSeller;
        }

        $transfers = null;
        $roundingGapCents = 0;
        if ($heldCents !== null) {
            $plan = $this->transferPlanner->plan($heldCents, $this->dueCentsByParticipant($distribution));
            $transfers = $this->settlementTransfers($project, $plan);
            $roundingGapCents = $plan->roundingGapCents;
        }

        return new ProjectSettlement(
            ProjectPaymentReference::of($project->getId()),
            $transfers,
            $reason,
            $roundingGapCents,
            new ClosingChecks(
                $this->pendingContributionCount($project),
                $unsoldProducts,
                $distribution->marginPercent,
                $transfers === null ? null : count($transfers),
                $transfers === null ? null : count(array_filter($transfers, static fn (SettlementTransfer $transfer): bool => $transfer->isReceived())),
            ),
        );
    }

    /**
     * @param list<IndustryProjectSale> $sales
     * @param list<UnsoldProduct> $unsoldProducts
     * @param array<int, float> $contractFeesByContractId
     */
    private function unavailableReason(IndustryProject $project, array $sales, array $unsoldProducts, DistributionResult $distribution, array $contractFeesByContractId): ?TransfersUnavailableReason
    {
        if ($sales === []) {
            return TransfersUnavailableReason::AwaitingSales;
        }

        if ($unsoldProducts !== []) {
            return TransfersUnavailableReason::UnsoldProducts;
        }

        $hasMarketSale = array_filter($sales, static fn (IndustryProjectSale $sale): bool => $sale->getChannel() === SaleChannel::Market) !== [];
        if ($hasMarketSale && $this->marketNetFactor($project) === null) {
            return TransfersUnavailableReason::UnknownFees;
        }

        foreach ($sales as $sale) {
            if ($sale->getContractId() !== null && !isset($contractFeesByContractId[$sale->getContractId()])) {
                return TransfersUnavailableReason::UnknownFees;
            }
        }

        if ($distribution->marginPercent === null) {
            return TransfersUnavailableReason::UnknownCost;
        }

        foreach ($distribution->members as $member) {
            if ($member->payoutTotal === null) {
                return TransfersUnavailableReason::UnknownCost;
            }
        }

        return null;
    }

    /**
     * @param list<IndustryProjectSale> $sales
     * @param array<int, float> $contractFeesByContractId
     *
     * @return array<string, int>|null
     */
    private function heldCentsByParticipant(IndustryProject $project, array $sales, ?float $marketNetFactor, array $contractFeesByContractId): ?array
    {
        $acceptedParticipantIdByUserId = [];
        foreach ($project->getMembers() as $participant) {
            $userId = $participant->getUser()?->getId();
            if ($userId !== null && $participant->getStatus() === ParticipantStatus::Accepted) {
                $acceptedParticipantIdByUserId[$userId->toRfc4122()] = $participant->getId()->toRfc4122();
            }
        }

        $held = [];
        foreach ($sales as $sale) {
            $holder = $sale->getSeller()?->getUser() ?? $sale->getRecordedBy();
            $participantId = $holder?->getId() === null ? null : ($acceptedParticipantIdByUserId[$holder->getId()->toRfc4122()] ?? null);
            if ($participantId === null) {
                return null;
            }

            $netFactor = $sale->getChannel() === SaleChannel::Market
                ? $marketNetFactor ?? throw new \LogicException('The fees of a market sale are checked before the revenue is held')
                : self::NO_FEE_FACTOR;
            $contractFees = $sale->getContractId() === null ? 0.0 : $contractFeesByContractId[$sale->getContractId()] ?? throw new \LogicException('The fees of a contract sale are checked before the revenue is held');
            $held[$participantId] = ($held[$participantId] ?? 0.0) + $sale->getTotalPrice() * $netFactor - $contractFees;
        }

        return array_map(static fn (float $isk): int => (int) round($isk * self::CENTS_PER_ISK), $held);
    }

    private function marketNetFactor(IndustryProject $project): ?float
    {
        $brokerFeePercent = $project->getBrokerFeePercent();
        $salesTaxPercent = $project->getSalesTaxPercent();

        return $brokerFeePercent === null || $salesTaxPercent === null ? null : 1 - ($brokerFeePercent + $salesTaxPercent) / self::PERCENT;
    }

    /**
     * @return array<string, int>
     */
    private function dueCentsByParticipant(DistributionResult $distribution): array
    {
        $due = [];
        foreach ($distribution->members as $member) {
            $due[$member->memberId] = (int) round(($member->payoutTotal ?? 0.0) * self::CENTS_PER_ISK);
        }

        return $due;
    }

    /**
     * @return list<SettlementTransfer>
     */
    private function settlementTransfers(IndustryProject $project, TransferPlan $plan): array
    {
        $participantsById = [];
        foreach ($project->getMembers() as $participant) {
            $participantsById[$participant->getId()->toRfc4122()] = $participant;
        }

        $donations = $this->journalVerifier->donationsOf($project);

        $receiptsByTransfer = [];
        foreach ($this->receiptRepository->findByProject($project) as $receipt) {
            $receiptsByTransfer[$receipt->getFromParticipant()->getId()->toRfc4122().'|'.$receipt->getToParticipant()->getId()->toRfc4122()] = $receipt;
        }

        return array_map(function (PlannedTransfer $planned) use ($participantsById, $receiptsByTransfer, $donations): SettlementTransfer {
            $from = $participantsById[$planned->fromParticipantId];
            $to = $participantsById[$planned->toParticipantId];
            $receipt = $receiptsByTransfer[$planned->fromParticipantId.'|'.$planned->toParticipantId] ?? null;
            $journalVerifiedAt = $this->journalVerifier->verifiedAt($donations, $from, $to, $planned->amountCents);
            $manualReceivedAt = $receipt !== null && $receipt->getAmountCents() === $planned->amountCents ? $receipt->getReceivedAt() : null;

            return new SettlementTransfer(
                $planned->fromParticipantId,
                PilotName::of($from->getUser()),
                $planned->toParticipantId,
                PilotName::of($to->getUser()),
                $to->getUser()?->getId()?->toRfc4122(),
                $planned->amountCents,
                $journalVerifiedAt ?? $manualReceivedAt,
                $journalVerifiedAt !== null,
            );
        }, $plan->transfers);
    }

    private function pendingContributionCount(IndustryProject $project): int
    {
        return $project->getContributions()->filter(
            static fn (IndustryProjectContribution $contribution): bool => $contribution->getStatus() === ContributionStatus::Pending,
        )->count();
    }
}
