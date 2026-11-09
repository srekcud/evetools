<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectContribution;
use App\Entity\IndustryProjectSale;
use App\Enum\ContributionType;
use App\Repository\IndustryProjectContributionRepository;
use App\Repository\IndustryProjectSaleRepository;

class ProjectDistributionService
{
    private const string FINAL_JOB_GROUP = 'final';
    private const float PERCENT = 100.0;

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

        $awaitingSales = $sales === [];
        $marginRatio = !$awaitingSales && $netRevenue !== null && $totalProjectCost !== null && $totalProjectCost > 0
            ? ($netRevenue - $totalProjectCost) / $totalProjectCost
            : null;
        $unsoldProducts = $awaitingSales ? [] : $this->unsoldProducts($project, $sales);

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
        );
    }

    /**
     * @param IndustryProjectSale[] $sales
     *
     * @return list<UnsoldProduct>
     */
    private function unsoldProducts(IndustryProject $project, array $sales): array
    {
        /** @var array<int, array{typeName: string, required: int}> $toSellByTypeId */
        $toSellByTypeId = [];
        foreach ($project->getBomItems() as $line) {
            if (!$line->isJob() || $line->getJobGroup() !== self::FINAL_JOB_GROUP) {
                continue;
            }

            $typeId = $line->getTypeId();
            $toSellByTypeId[$typeId] = [
                'typeName' => $toSellByTypeId[$typeId]['typeName'] ?? $line->getTypeName(),
                'required' => ($toSellByTypeId[$typeId]['required'] ?? 0) + $line->getRequiredQuantity(),
            ];
        }

        if ($toSellByTypeId === []) {
            return [];
        }

        $soldByTypeId = [];
        $latestSaleByTypeId = [];
        foreach ($sales as $sale) {
            $typeId = $sale->getTypeId();
            $soldByTypeId[$typeId] = ($soldByTypeId[$typeId] ?? 0) + $sale->getQuantity();
            $latest = $latestSaleByTypeId[$typeId] ?? null;
            if ($latest === null || $this->isMoreRecent($sale, $latest)) {
                $latestSaleByTypeId[$typeId] = $sale;
            }
        }

        $unsoldProducts = [];
        foreach ($toSellByTypeId as $typeId => $toSell) {
            $unsoldQuantity = $toSell['required'] - ($soldByTypeId[$typeId] ?? 0);
            if ($unsoldQuantity <= 0) {
                continue;
            }

            $unsoldProducts[] = new UnsoldProduct(
                $typeId,
                $toSell['typeName'],
                $unsoldQuantity,
                isset($latestSaleByTypeId[$typeId]) ? $latestSaleByTypeId[$typeId]->getUnitPrice() : null,
            );
        }

        return $unsoldProducts;
    }

    private function isMoreRecent(IndustryProjectSale $candidate, IndustryProjectSale $reference): bool
    {
        $bySoldAt = $candidate->getSoldAt() <=> $reference->getSoldAt();

        return $bySoldAt !== 0 ? $bySoldAt > 0 : $candidate->getCreatedAt() > $reference->getCreatedAt();
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
