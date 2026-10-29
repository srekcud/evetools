<?php

declare(strict_types=1);

namespace App\Service\Industry;

use App\Entity\IndustryProject;
use App\Enum\IndustryActivityType;
use App\Enum\ProjectCorrectionStatus;
use App\Enum\ProjectStatus;
use App\Repository\IndustryProjectRepository;
use App\Repository\Sde\IndustryActivityProductRepository;
use Doctrine\ORM\EntityManagerInterface;

class ReactionProjectSellPriceRecalculation extends ProjectByProjectCorrection
{
    public function __construct(
        IndustryProjectRepository $projectRepository,
        EntityManagerInterface $entityManager,
        private readonly IndustryActivityProductRepository $activityProductRepository,
        private readonly EstimatedSellPriceEstimator $sellPriceEstimator,
        IndustryCalculationService $calculationService,
    ) {
        parent::__construct($projectRepository, $entityManager, $calculationService);
    }

    /**
     * @return list<ProjectCorrectionOutcome>
     */
    public function run(\DateTimeImmutable $createdBefore, bool $dryRun): array
    {
        return $this->correctProjects(
            fn (IndustryProject $project): bool => $this->isReactionProjectToRecalculate($project, $createdBefore),
            fn (IndustryProject $project): ProjectCorrectionOutcome => $this->recalculated($project, $dryRun),
        );
    }

    private function isReactionProjectToRecalculate(IndustryProject $project, \DateTimeImmutable $createdBefore): bool
    {
        if ($project->getCreatedAt() >= $createdBefore
            || $project->getStatus() !== ProjectStatus::Active
            || $project->getEstimatedSellPrice() === null) {
            return false;
        }

        $producer = SplitGroupMaterials::producerOf($this->activityProductRepository, $project->getProductTypeId());

        return $producer->getActivityId() === IndustryActivityType::Reaction->value && $producer->getQuantity() > 1;
    }

    private function recalculated(IndustryProject $project, bool $dryRun): ProjectCorrectionOutcome
    {
        $estimate = $this->sellPriceEstimator->estimateFor($project);
        if ($estimate === null) {
            return $this->outcomeOf($project, ProjectCorrectionStatus::Failed, reason: 'No Jita nor structure sell price for type ID '.$project->getProductTypeId());
        }

        $recordedPrice = $project->getEstimatedSellPrice() ?? throw new \LogicException('A selected project has an estimated sell price');
        $recordedSource = $project->getEstimatedSellPriceSource() ?? throw new \UnexpectedValueException('The estimated sell price has no source');
        $recordedTax = $project->getEstimatedTaxAmount() ?? throw new \UnexpectedValueException('The estimated sell price has no tax');

        if ($estimate->amount === $recordedPrice && $estimate->source === $recordedSource && $estimate->taxAmount === $recordedTax) {
            return $this->outcomeOf($project, ProjectCorrectionStatus::Unchanged);
        }

        $change = self::describe($recordedPrice, $recordedSource, $recordedTax).' -> '.self::describe($estimate->amount, $estimate->source, $estimate->taxAmount);

        if (!$dryRun) {
            $project->recordEstimatedSellPrice($estimate->amount, $estimate->source, $estimate->taxAmount);
            $this->entityManager->flush();
        }

        return $this->outcomeOf($project, ProjectCorrectionStatus::Changed, $change);
    }

    private static function describe(float $amount, string $source, float $taxAmount): string
    {
        return \sprintf('sell %s (%s), tax %s', number_format($amount, 2), $source, number_format($taxAmount, 2));
    }
}
