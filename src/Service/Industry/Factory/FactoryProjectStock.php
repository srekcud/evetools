<?php

declare(strict_types=1);

namespace App\Service\Industry\Factory;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;
use App\Entity\User;
use App\Enum\ContributionStatus;
use App\Enum\ContributionType;
use App\Repository\IndustryProjectParticipantRepository;
use App\Service\Industry\Project\ProjectSurplusBuilder;
use App\Service\Industry\Project\ProjectSurplusSource;

final readonly class FactoryProjectStock
{
    private const array STATE_ORDER = [
        FactoryProjectStockLine::RESERVED => 0,
        FactoryProjectStockLine::CONSUMED => 1,
        FactoryProjectStockLine::RETURNED => 2,
    ];

    public function __construct(
        private IndustryProjectParticipantRepository $participantRepository,
        private ProjectSurplusBuilder $surplusBuilder,
    ) {
    }

    /**
     * @return list<FactoryProjectStockLine>
     */
    public function of(User $user): array
    {
        $lines = [];
        foreach ($this->participantRepository->findAcceptedWithProjectsByUser($user) as $membership) {
            array_push($lines, ...$this->linesOf($membership->getProject()));
        }

        usort($lines, static fn (FactoryProjectStockLine $left, FactoryProjectStockLine $right): int => [(string) $left->projectName, $left->projectId, $left->typeId, self::STATE_ORDER[$left->state]]
            <=> [(string) $right->projectName, $right->projectId, $right->typeId, self::STATE_ORDER[$right->state]]);

        return $lines;
    }

    /**
     * @return list<FactoryProjectStockLine>
     */
    private function linesOf(IndustryProject $project): array
    {
        $returnedByLineId = $this->returnedQuantityByLineId($project);
        $quantities = [];
        $typeNames = [];
        foreach ($project->getBomItems() as $line) {
            $contributed = $this->contributedQuantityOf($project, $line);
            if ($contributed <= 0) {
                continue;
            }
            $typeNames[$line->getTypeId()] = $line->getTypeName();
            if (!$project->isCompleted()) {
                $this->add($quantities, $line->getTypeId(), FactoryProjectStockLine::RESERVED, $contributed);
                continue;
            }
            $returned = $returnedByLineId[$line->getId()->toRfc4122()] ?? 0;
            $this->add($quantities, $line->getTypeId(), FactoryProjectStockLine::CONSUMED, $contributed - $returned);
            $this->add($quantities, $line->getTypeId(), FactoryProjectStockLine::RETURNED, $returned);
        }

        $lines = [];
        foreach ($quantities as $typeId => $quantityByState) {
            foreach ($quantityByState as $state => $quantity) {
                if ($quantity > 0) {
                    $lines[] = new FactoryProjectStockLine(
                        $project->getId()->toRfc4122(),
                        $project->getName(),
                        $project->getStatus()->value,
                        $typeId,
                        $typeNames[$typeId],
                        $state,
                        $quantity,
                    );
                }
            }
        }

        return $lines;
    }

    /**
     * @param array<int, array<string, int>> $quantities
     */
    private function add(array &$quantities, int $typeId, string $state, int $quantity): void
    {
        $quantities[$typeId][$state] = ($quantities[$typeId][$state] ?? 0) + $quantity;
    }

    private function contributedQuantityOf(IndustryProject $project, IndustryProjectBomLine $line): int
    {
        $contributed = 0;
        foreach ($project->getContributions() as $contribution) {
            if (
                $contribution->getBomItem() === $line
                && ContributionType::Material === $contribution->getType()
                && ContributionStatus::Approved === $contribution->getStatus()
            ) {
                $contributed += $contribution->getQuantity();
            }
        }

        return $contributed;
    }

    /**
     * @return array<string, int>
     */
    private function returnedQuantityByLineId(IndustryProject $project): array
    {
        $returned = [];
        foreach ($this->surplusBuilder->build($project)->lines as $surplusLine) {
            if (ProjectSurplusSource::Contribution === $surplusLine->source) {
                $returned[$surplusLine->lineId] = ($returned[$surplusLine->lineId] ?? 0) + $surplusLine->surplusQuantity;
            }
        }

        return $returned;
    }
}
