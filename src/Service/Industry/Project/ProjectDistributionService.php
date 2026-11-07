<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

use App\Entity\IndustryProjectContribution;
use App\Entity\IndustryProject;
use App\Enum\ContributionType;
use App\Repository\IndustryProjectContributionRepository;
use App\Repository\IndustryProjectSaleRepository;

class ProjectDistributionService
{
    public function __construct(
        private readonly IndustryProjectContributionRepository $contributionRepository,
        private readonly IndustryProjectSaleRepository $saleRepository,
    ) {}

    public function calculateDistribution(IndustryProject $project): DistributionResult
    {
        $sales = $this->saleRepository->findBy(['project' => $project]);
        $totalRevenue = array_sum(array_map(
            static fn ($sale) => $sale->getTotalPrice(),
            $sales,
        ));

        $brokerFeePercent = $project->getBrokerFeePercent();
        $salesTaxPercent = $project->getSalesTaxPercent();
        $feesAreKnown = $brokerFeePercent !== null && $salesTaxPercent !== null;
        $brokerFee = $feesAreKnown ? $totalRevenue * $brokerFeePercent / 100 : null;
        $salesTax = $feesAreKnown ? $totalRevenue * $salesTaxPercent / 100 : null;
        $netRevenue = $feesAreKnown ? $totalRevenue - $brokerFee - $salesTax : null;

        $approvedContributions = $this->contributionRepository->findApprovedByProject($project);
        $totalProjectCost = $this->sumOfValues(...$approvedContributions);

        $marginRatio = $netRevenue !== null && $totalProjectCost !== null && $totalProjectCost > 0
            ? ($netRevenue - $totalProjectCost) / $totalProjectCost
            : null;

        $members = $this->buildMemberDistributions($approvedContributions, $totalProjectCost, $marginRatio);

        return new DistributionResult(
            totalRevenue: $totalRevenue,
            brokerFee: $brokerFee,
            salesTax: $salesTax,
            netRevenue: $netRevenue,
            totalProjectCost: $totalProjectCost,
            marginPercent: $marginRatio === null ? null : $marginRatio * 100.0,
            members: $members,
        );
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
