<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectContribution;
use App\Enum\ContributionStatus;
use App\Enum\ContributionType;
use App\Enum\ProjectStatus;
use App\Repository\IndustryProjectRepository;
use App\Service\Mercure\MercurePublisherService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class ContainerDeliveryConfirmer
{
    private const string CONTRIBUTION_SYNCED_ACTION = 'contribution_synced';

    public function __construct(
        private readonly ProjectContainerService $containerService,
        private readonly ProjectContributionService $contributionService,
        private readonly IndustryProjectRepository $projectRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly MercurePublisherService $mercurePublisher,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function confirmProject(IndustryProject $project): int
    {
        if (\in_array($project->getStatus(), [ProjectStatus::Selling, ProjectStatus::Completed], true)) {
            return 0;
        }

        $proposalsByLine = $this->confirmableProposalsByLine($project);
        if ($proposalsByLine === []) {
            return 0;
        }

        $stock = $this->containerService->containerStockOf($project);
        if ($stock->unknownReason !== null) {
            return 0;
        }

        $confirmed = [];
        foreach ($proposalsByLine as ['line' => $line, 'proposals' => $proposals]) {
            $stockQuantity = $this->quantityInStock($stock, $line->getTypeId());
            $cumulatedQuantity = $line->getFulfilledQuantity();
            foreach ($proposals as $proposal) {
                if ($stockQuantity < $cumulatedQuantity + $proposal->getQuantity()) {
                    break;
                }

                $this->contributionService->confirmDelivery($proposal);
                $cumulatedQuantity += $proposal->getQuantity();
                $confirmed[] = ['proposal' => $proposal, 'line' => $line];
            }
        }

        if ($confirmed === []) {
            return 0;
        }

        $this->entityManager->flush();

        foreach ($confirmed as ['proposal' => $proposal, 'line' => $line]) {
            $this->mercurePublisher->publishGroupProjectEvent(
                $project->getId()->toRfc4122(),
                self::CONTRIBUTION_SYNCED_ACTION,
                ['contributionId' => $proposal->getId()->toRfc4122(), 'bomItemId' => $line->getId()->toRfc4122()],
            );
        }

        $this->logger->info('Deliveries confirmed against the project container', [
            'projectId' => $project->getId()->toRfc4122(),
            'confirmedCount' => \count($confirmed),
        ]);

        return \count($confirmed);
    }

    public function confirmOpenProjectsOfCorporation(int $corporationId): int
    {
        $confirmedCount = 0;
        foreach ($this->projectRepository->findOpenOwnedByCorporation($corporationId) as $project) {
            $confirmedCount += $this->confirmProject($project);
        }

        return $confirmedCount;
    }

    /**
     * @return list<array{line: IndustryProjectBomLine, proposals: list<IndustryProjectContribution>}>
     */
    private function confirmableProposalsByLine(IndustryProject $project): array
    {
        $proposalsByLineId = [];
        foreach ($project->getContributions() as $contribution) {
            $line = $contribution->getBomItem();
            if ($line !== null && $this->isConfirmable($contribution, $line)) {
                $proposalsByLineId[$line->getId()->toRfc4122()][] = $contribution;
            }
        }

        $proposalsByLine = [];
        foreach ($project->getBomItems() as $line) {
            $proposals = $proposalsByLineId[$line->getId()->toRfc4122()] ?? null;
            if ($proposals === null) {
                continue;
            }

            usort($proposals, static fn (IndustryProjectContribution $first, IndustryProjectContribution $second): int => $first->getCreatedAt() <=> $second->getCreatedAt());
            $proposalsByLine[] = ['line' => $line, 'proposals' => $proposals];
        }

        return $proposalsByLine;
    }

    private function isConfirmable(IndustryProjectContribution $contribution, IndustryProjectBomLine $line): bool
    {
        return ContributionStatus::Pending === $contribution->getStatus()
            && $contribution->isAutoDetected()
            && ContributionType::Material === $contribution->getType()
            && null === $contribution->getVerifiedAt()
            && !$line->isJob();
    }

    private function quantityInStock(ProjectContainerStock $stock, int $typeId): int
    {
        return \array_key_exists($typeId, $stock->quantitiesByTypeId) ? $stock->quantitiesByTypeId[$typeId] : 0;
    }
}
