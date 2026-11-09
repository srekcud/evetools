<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectContribution;
use App\Entity\IndustryProjectSale;
use App\Enum\ContributionType;
use App\Enum\SaleChannel;
use App\Repository\IndustryProjectContributionRepository;
use App\Repository\IndustryProjectSaleRepository;

class ProjectDistributionService
{
    private const float PERCENT = 100.0;

    public function __construct(
        private readonly IndustryProjectContributionRepository $contributionRepository,
        private readonly IndustryProjectSaleRepository $saleRepository,
        private readonly UnsoldProductsOfProject $unsoldProducts = new UnsoldProductsOfProject(),
        private readonly ?ContractSaleFees $contractSaleFees = null,
    ) {}

    public function calculateDistribution(IndustryProject $project): DistributionResult
    {
        $sales = $this->saleRepository->findBy(['project' => $project]);
        $marketRevenue = $this->revenueOfChannel($sales, SaleChannel::Market);
        $contractRevenue = $this->revenueOfChannel($sales, SaleChannel::Contract);
        $totalRevenue = $marketRevenue + $contractRevenue;

        $brokerFeePercent = $project->getBrokerFeePercent();
        $salesTaxPercent = $project->getSalesTaxPercent();
        $feesAreKnown = $marketRevenue <= 0.0 || ($brokerFeePercent !== null && $salesTaxPercent !== null);
        $brokerFee = $feesAreKnown ? $this->feeOn($marketRevenue, $brokerFeePercent) : null;
        $salesTax = $feesAreKnown ? $this->feeOn($marketRevenue, $salesTaxPercent) : null;
        $contractFees = $this->contractFeesOf($sales);
        $netRevenue = $brokerFee === null || $salesTax === null || $contractFees === null ? null : $totalRevenue - $brokerFee - $salesTax - $contractFees;

        $approvedContributions = $this->contributionRepository->findApprovedByProject($project);
        $totalProjectCost = $this->sumOfValues(...$approvedContributions);

        $awaitingSales = $sales === [];
        $marginRatio = !$awaitingSales && $netRevenue !== null && $totalProjectCost !== null && $totalProjectCost > 0
            ? ($netRevenue - $totalProjectCost) / $totalProjectCost
            : null;
        $unsoldProducts = $awaitingSales ? [] : $this->unsoldProducts->of($project, $sales);

        $members = $this->buildMemberDistributions($approvedContributions, $totalProjectCost, $marginRatio);

        return new DistributionResult(
            totalRevenue: $totalRevenue,
            brokerFee: $brokerFee,
            salesTax: $salesTax,
            netRevenue: $awaitingSales ? null : $netRevenue,
            totalProjectCost: $totalProjectCost,
            marginPercent: $marginRatio === null ? null : $marginRatio * self::PERCENT,
            members: $members,
            awaitingSales: $awaitingSales,
            unsoldProducts: $unsoldProducts,
            projectedMarginPercent: $this->projectedMarginPercent(
                $unsoldProducts,
                $netRevenue,
                $totalProjectCost,
                $brokerFeePercent,
                $salesTaxPercent,
            ),
            marketRevenue: $marketRevenue,
            contractRevenue: $contractRevenue,
            contractFees: $contractFees,
        );
    }

    /**
     * @param list<IndustryProjectSale> $sales
     */
    private function contractFeesOf(array $sales): ?float
    {
        $attachedSales = array_filter($sales, static fn (IndustryProjectSale $sale): bool => $sale->getContractId() !== null);
        if ($attachedSales === []) {
            return 0.0;
        }

        return $this->contractSaleFees?->totalOf($attachedSales);
    }

    private function feeOn(float $marketRevenue, ?float $feePercent): ?float
    {
        if ($marketRevenue <= 0.0) {
            return 0.0;
        }

        return $feePercent === null ? null : $marketRevenue * $feePercent / self::PERCENT;
    }

    /**
     * @param list<IndustryProjectSale> $sales
     */
    private function revenueOfChannel(array $sales, SaleChannel $channel): float
    {
        return array_sum(array_map(
            static fn (IndustryProjectSale $sale): float => $sale->getTotalPrice(),
            array_filter($sales, static fn (IndustryProjectSale $sale): bool => $sale->getChannel() === $channel),
        ));
    }

    /**
     * @param list<UnsoldProduct> $unsoldProducts
     */
    private function projectedMarginPercent(
        array $unsoldProducts,
        ?float $netRevenue,
        ?float $totalProjectCost,
        ?float $brokerFeePercent,
        ?float $salesTaxPercent,
    ): ?float {
        if ($unsoldProducts === [] || $netRevenue === null || $totalProjectCost === null || $totalProjectCost <= 0
            || $brokerFeePercent === null || $salesTaxPercent === null) {
            return null;
        }

        $unsoldRevenue = 0.0;
        foreach ($unsoldProducts as $unsoldProduct) {
            if ($unsoldProduct->lastUnitPrice === null) {
                return null;
            }

            $unsoldRevenue += $unsoldProduct->unsoldQuantity * $unsoldProduct->lastUnitPrice;
        }

        $projectedNetRevenue = $netRevenue + $unsoldRevenue * (1 - ($brokerFeePercent + $salesTaxPercent) / self::PERCENT);

        return ($projectedNetRevenue - $totalProjectCost) / $totalProjectCost * self::PERCENT;
    }

    /**
     * @param IndustryProjectContribution[] $contributions
     * @return ParticipantDistribution[]
     */
    private function buildMemberDistributions(array $contributions, ?float $totalProjectCost, ?float $marginRatio): array
    {
        /** @var array<string, array{contributions: IndustryProjectContribution[], member: \App\Entity\IndustryProjectParticipant}> $grouped */
        $grouped = [];

        foreach ($contributions as $contribution) {
            $member = $contribution->getMember();
            $memberId = $member->getId()->toRfc4122();

            if (!isset($grouped[$memberId])) {
                $grouped[$memberId] = [
                    'contributions' => [],
                    'member' => $member,
                ];
            }

            $grouped[$memberId]['contributions'][] = $contribution;
        }

        $result = [];

        foreach ($grouped as $memberId => $data) {
            $member = $data['member'];
            $memberContributions = $data['contributions'];

            $costOfType = fn (ContributionType $type): ?float => $this->sumOfValues(...array_filter(
                $memberContributions,
                static fn (IndustryProjectContribution $contribution): bool => $contribution->getType() === $type,
            ));
            $totalCosts = $this->sumOfValues(...$memberContributions);
            $materialCosts = $costOfType(ContributionType::Material);
            $jobInstallCosts = $costOfType(ContributionType::JobInstall);
            $bpcCosts = $costOfType(ContributionType::Bpc);
            $lineRentalCosts = $costOfType(ContributionType::LineRental);
            $courierCosts = $costOfType(ContributionType::Courier);

            $sharePercent = $totalCosts !== null && $totalProjectCost !== null && $totalProjectCost > 0
                ? $totalCosts / $totalProjectCost * 100
                : null;

            $profitPart = $totalCosts === null || $marginRatio === null ? null : $totalCosts * $marginRatio;
            $payoutTotal = $profitPart === null ? null : $totalCosts + $profitPart;

            $characterName = PilotName::of($member->getUser());

            $result[] = new ParticipantDistribution(
                memberId: $memberId,
                characterName: $characterName,
                totalCostsEngaged: $totalCosts,
                materialCosts: $materialCosts,
                jobInstallCosts: $jobInstallCosts,
                bpcCosts: $bpcCosts,
                lineRentalCosts: $lineRentalCosts,
                courierCosts: $courierCosts,
                sharePercent: $sharePercent,
                profitPart: $profitPart,
                payoutTotal: $payoutTotal,
            );
        }

        return $result;
    }

    private function sumOfValues(IndustryProjectContribution ...$contributions): ?float
    {
        $sum = 0.0;

        foreach ($contributions as $contribution) {
            $value = $contribution->getEstimatedValue();

            if ($value === null) {
                return null;
            }

            $sum += $value;
        }

        return $sum;
    }
}
