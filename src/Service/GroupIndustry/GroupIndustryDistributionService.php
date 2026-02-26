<?php

declare(strict_types=1);

namespace App\Service\GroupIndustry;

use App\Entity\GroupIndustryContribution;
use App\Entity\GroupIndustryProject;
use App\Enum\ContributionType;
use App\Repository\GroupIndustryContributionRepository;
use App\Repository\GroupIndustrySaleRepository;

class GroupIndustryDistributionService
{
    public function __construct(
        private readonly GroupIndustryContributionRepository $contributionRepository,
        private readonly GroupIndustrySaleRepository $saleRepository,
    ) {}

    public function calculateDistribution(GroupIndustryProject $project): DistributionResult
    {
        $sales = $this->saleRepository->findBy(['project' => $project]);
        $totalRevenue = array_sum(array_map(
            static fn ($sale) => $sale->getTotalPrice(),
            $sales,
        ));

        $brokerFee = $totalRevenue * $project->getBrokerFeePercent() / 100;
        $salesTax = $totalRevenue * $project->getSalesTaxPercent() / 100;
        $netRevenue = $totalRevenue - $brokerFee - $salesTax;

        $approvedContributions = $this->contributionRepository->findApprovedByProject($project);
        $totalProjectCost = array_sum(array_map(
            static fn (GroupIndustryContribution $c) => $c->getEstimatedValue(),
            $approvedContributions,
        ));

        $marginPercent = $totalProjectCost > 0
            ? ($netRevenue - $totalProjectCost) / $totalProjectCost
            : 0.0;

        $members = $this->buildMemberDistributions($approvedContributions, $totalProjectCost, $marginPercent);

        return new DistributionResult(
            totalRevenue: $totalRevenue,
            brokerFee: $brokerFee,
            salesTax: $salesTax,
            netRevenue: $netRevenue,
            totalProjectCost: $totalProjectCost,
            marginPercent: $marginPercent,
            members: $members,
        );
    }

    /**
     * @param GroupIndustryContribution[] $contributions
     * @return MemberDistribution[]
     */
    private function buildMemberDistributions(array $contributions, float $totalProjectCost, float $marginPercent): array
    {
        /** @var array<string, array{contributions: GroupIndustryContribution[], member: \App\Entity\GroupIndustryProjectMember}> $grouped */
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

            $totalCosts = 0.0;
            $materialCosts = 0.0;
            $jobInstallCosts = 0.0;
            $bpcCosts = 0.0;
            $lineRentalCosts = 0.0;

            foreach ($memberContributions as $contribution) {
                $value = $contribution->getEstimatedValue();
                $totalCosts += $value;

                match ($contribution->getType()) {
                    ContributionType::Material => $materialCosts += $value,
                    ContributionType::JobInstall => $jobInstallCosts += $value,
                    ContributionType::Bpc => $bpcCosts += $value,
                    ContributionType::LineRental => $lineRentalCosts += $value,
                };
            }

            $sharePercent = $totalProjectCost > 0
                ? $totalCosts / $totalProjectCost * 100
                : 0.0;

            $profitPart = $totalCosts * $marginPercent;
            $payoutTotal = $totalCosts + $profitPart;

            $characterName = $member->getUser()->getMainCharacter()?->getName() ?? 'Unknown';

            $result[] = new MemberDistribution(
                memberId: $memberId,
                characterName: $characterName,
                totalCostsEngaged: $totalCosts,
                materialCosts: $materialCosts,
                jobInstallCosts: $jobInstallCosts,
                bpcCosts: $bpcCosts,
                lineRentalCosts: $lineRentalCosts,
                sharePercent: $sharePercent,
                profitPart: $profitPart,
                payoutTotal: $payoutTotal,
            );
        }

        return $result;
    }
}
