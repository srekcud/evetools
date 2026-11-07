<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectContribution;
use App\Enum\ContributionStatus;
use App\Enum\ContributionType;

final readonly class ProjectSurplusBuilder
{
    public function build(IndustryProject $project): ProjectSurplus
    {
        if (!$project->isCompleted()) {
            return new ProjectSurplus([], 0.0);
        }

        $lines = [];
        foreach ($project->getBomItems() as $bomLine) {
            $contributions = $this->countedContributionsOf($project, $bomLine);
            $contributedQuantity = array_sum(array_map(static fn (IndustryProjectContribution $contribution): int => $contribution->getQuantity(), $contributions));
            $surplusQuantity = $contributedQuantity - $bomLine->getRequiredQuantity();
            if ($surplusQuantity <= 0) {
                continue;
            }

            $unitPrice = $this->unitPriceOf($contributions, $contributedQuantity);
            $lines[] = new ProjectSurplusLine(
                $bomLine->getId()->toRfc4122(),
                $bomLine->getTypeId(),
                $bomLine->getTypeName(),
                $bomLine->getRequiredQuantity(),
                $contributedQuantity,
                $surplusQuantity,
                $unitPrice,
                $unitPrice === null ? null : $surplusQuantity * $unitPrice,
            );
        }

        usort($lines, static fn (ProjectSurplusLine $left, ProjectSurplusLine $right): int => $left->typeName <=> $right->typeName ?: $left->typeId <=> $right->typeId);

        return new ProjectSurplus($lines, $this->totalValueOf($lines));
    }

    /**
     * @return list<IndustryProjectContribution>
     */
    private function countedContributionsOf(IndustryProject $project, IndustryProjectBomLine $bomLine): array
    {
        $contributions = [];
        foreach ($project->getContributions() as $contribution) {
            if (
                $contribution->getBomItem() === $bomLine
                && $contribution->getType() === ContributionType::Material
                && $contribution->getStatus() === ContributionStatus::Approved
            ) {
                $contributions[] = $contribution;
            }
        }

        return $contributions;
    }

    /**
     * @param list<IndustryProjectContribution> $contributions
     */
    private function unitPriceOf(array $contributions, int $contributedQuantity): ?float
    {
        $totalValue = 0.0;
        foreach ($contributions as $contribution) {
            $estimatedValue = $contribution->getEstimatedValue();
            if ($estimatedValue === null) {
                return null;
            }
            $totalValue += $estimatedValue;
        }

        return $totalValue / $contributedQuantity;
    }

    /**
     * @param list<ProjectSurplusLine> $lines
     */
    private function totalValueOf(array $lines): ?float
    {
        $totalValue = 0.0;
        foreach ($lines as $line) {
            if ($line->surplusValue === null) {
                return null;
            }
            $totalValue += $line->surplusValue;
        }

        return $totalValue;
    }
}
