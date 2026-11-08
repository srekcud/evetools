<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

use App\Entity\CachedIndustryJob;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectContribution;
use App\Entity\IndustryProjectJobMatch;
use App\Entity\IndustryProjectSale;
use App\Enum\ContributionStatus;

final readonly class ProjectChainBuilder
{
    private const string STAGE_BLUEPRINT = 'blueprint';
    private const string STAGE_MATERIAL = 'material';
    private const string STAGE_COMPONENT = 'component';
    private const string STAGE_PRODUCT = 'product';
    private const string STAGE_SALE = 'sale';

    private const array STAGE_ORDER = [
        self::STAGE_BLUEPRINT => 0,
        self::STAGE_MATERIAL => 1,
        self::STAGE_COMPONENT => 2,
        self::STAGE_PRODUCT => 3,
    ];

    private const string STATUS_TODO = 'todo';
    private const string STATUS_ACTIVE = 'active';
    private const string STATUS_DONE = 'done';
    private const string STATUS_BLOCKED = 'blocked';
    private const string STATUS_UNKNOWN = 'unknown';

    private const string COST_NONE = 'none';
    private const string COST_UNKNOWN = 'unknown';
    private const string COST_KNOWN = 'known';

    private const string SALE_NODE_ID = 'sale';

    private const string PLANNED_STRUCTURE_NOT_CONFIGURED = 'not_configured';
    private const array PLANNED_STRUCTURE_STATUSES = [
        'assigned',
        'best_in_favorite_system',
        'best_configured',
        'best_outside_favorite_system',
        'best_by_total_cost',
        self::PLANNED_STRUCTURE_NOT_CONFIGURED,
    ];

    private const array LINK_STATUSES_IN_PROGRESS = [
        CachedIndustryJob::STATUS_ACTIVE,
        CachedIndustryJob::STATUS_PAUSED,
        CachedIndustryJob::STATUS_READY,
    ];

    /**
     * @param array<IndustryProjectBomLine>      $bomLines
     * @param array<IndustryProjectContribution> $contributions
     * @param array<IndustryProjectSale>         $sales
     * @param array<string, string>              $structureNamesById
     * @param array<int, int>                    $levelByTypeId
     */
    public function build(IndustryProject $project, array $bomLines, array $contributions, array $sales, array $structureNamesById, array $levelByTypeId = []): ProjectChain
    {
        $orderedLines = $this->orderedLines($bomLines);
        $contributionsByLineId = $this->approvedContributionsByLineId($contributions);
        $jobNodeIdByTypeId = $this->jobNodeIdByTypeId($orderedLines);

        $parentIdByLineId = [];
        $componentsByParentId = [];
        foreach ($orderedLines as $line) {
            $parentId = $this->parentIdOf($line, $jobNodeIdByTypeId);
            $parentIdByLineId[self::lineId($line)] = $parentId;
            if (null !== $parentId && self::STAGE_COMPONENT === $this->stageOf($line)) {
                $componentsByParentId[$parentId][] = $line;
            }
        }

        $jobStatusByLineId = [];
        $nodes = [];
        foreach ($orderedLines as $line) {
            $nodes[] = $this->nodeOf(
                $line,
                $parentIdByLineId[self::lineId($line)],
                $contributionsByLineId[self::lineId($line)] ?? [],
                $structureNamesById,
                $componentsByParentId,
                $jobStatusByLineId,
                $levelByTypeId,
            );
        }
        $nodes[] = $this->saleNode($project, $sales);

        return new ProjectChain($nodes);
    }

    /**
     * @param array<IndustryProjectBomLine> $bomLines
     * @return list<IndustryProjectBomLine>
     */
    private function orderedLines(array $bomLines): array
    {
        usort($bomLines, fn (IndustryProjectBomLine $left, IndustryProjectBomLine $right): int => self::STAGE_ORDER[$this->stageOf($left)] <=> self::STAGE_ORDER[$this->stageOf($right)]
            ?: $left->getTypeName() <=> $right->getTypeName()
            ?: self::lineId($left) <=> self::lineId($right));

        return $bomLines;
    }

    private function stageOf(IndustryProjectBomLine $line): string
    {
        if (!$line->isJob()) {
            return self::STAGE_MATERIAL;
        }

        return match ($line->getJobGroup()) {
            'blueprint' => self::STAGE_BLUEPRINT,
            'component' => self::STAGE_COMPONENT,
            'final' => self::STAGE_PRODUCT,
            default => throw new \UnexpectedValueException(\sprintf('BOM line %s has the unknown job group "%s".', self::lineId($line), $line->getJobGroup() ?? 'null')),
        };
    }

    /**
     * @param list<IndustryProjectBomLine> $orderedLines
     * @return array<int, string>
     */
    private function jobNodeIdByTypeId(array $orderedLines): array
    {
        $jobNodeIdByTypeId = [];
        foreach ($orderedLines as $line) {
            if (\in_array($this->stageOf($line), [self::STAGE_COMPONENT, self::STAGE_PRODUCT], true)) {
                $jobNodeIdByTypeId[$line->getTypeId()] ??= self::lineId($line);
            }
        }

        return $jobNodeIdByTypeId;
    }

    /**
     * @param array<int, string> $jobNodeIdByTypeId
     */
    private function parentIdOf(IndustryProjectBomLine $line, array $jobNodeIdByTypeId): ?string
    {
        $consumerTypeId = $line->getParentTypeId();
        $consumerNodeId = null === $consumerTypeId ? null : $jobNodeIdByTypeId[$consumerTypeId] ?? null;
        $stage = $this->stageOf($line);

        if (self::STAGE_COMPONENT === $stage) {
            return $consumerNodeId;
        }
        if (self::STAGE_BLUEPRINT === $stage) {
            return $jobNodeIdByTypeId[$line->getTypeId()] ?? $consumerNodeId;
        }

        return null;
    }

    /**
     * @param array<IndustryProjectContribution> $contributions
     * @return array<string, list<IndustryProjectContribution>>
     */
    private function approvedContributionsByLineId(array $contributions): array
    {
        $contributionsByLineId = [];
        foreach ($contributions as $contribution) {
            $line = $contribution->getBomItem();
            if (null === $line || ContributionStatus::Approved !== $contribution->getStatus()) {
                continue;
            }
            $contributionsByLineId[self::lineId($line)][] = $contribution;
        }

        return $contributionsByLineId;
    }

    /**
     * @param list<IndustryProjectContribution>             $approvedContributions
     * @param array<string, string>                         $structureNamesById
     * @param array<string, list<IndustryProjectBomLine>>   $componentsByParentId
     * @param array<string, string>                         $jobStatusByLineId
     * @param array<int, int>                               $levelByTypeId
     */
    private function nodeOf(
        IndustryProjectBomLine $line,
        ?string $parentId,
        array $approvedContributions,
        array $structureNamesById,
        array $componentsByParentId,
        array &$jobStatusByLineId,
        array $levelByTypeId,
    ): ProjectChainNode {
        $stage = $this->stageOf($line);
        [$costState, $realCost] = $this->costOf($approvedContributions);
        $isJob = $line->isJob();
        $jobs = $isJob ? $this->jobsOf($line) : [];

        return new ProjectChainNode(
            self::lineId($line),
            $stage,
            $parentId,
            $isJob ? $levelByTypeId[$line->getTypeId()] ?? null : null,
            $line->getTypeId(),
            $line->getTypeName(),
            $line->getActivityType(),
            $isJob ? $line->getRuns() : null,
            $isJob ? $line->getMeLevel() : null,
            $isJob ? $line->getTeLevel() : null,
            $line->getRequiredQuantity(),
            $line->getFulfilledQuantity(),
            $isJob ? $this->jobStatusOf($line, $componentsByParentId, $jobStatusByLineId) : $this->materialStatusOf($line),
            $costState,
            $realCost,
            $this->suppliersOf($approvedContributions),
            $jobs,
            $this->structuresOf($jobs),
            \in_array($stage, [self::STAGE_COMPONENT, self::STAGE_PRODUCT], true) ? $this->plannedStructureOf($line, $structureNamesById) : null,
            null,
            null,
        );
    }

    private function materialStatusOf(IndustryProjectBomLine $line): string
    {
        return match (true) {
            $line->getFulfilledQuantity() >= $line->getRequiredQuantity() => self::STATUS_DONE,
            $line->getFulfilledQuantity() > 0 => self::STATUS_ACTIVE,
            default => self::STATUS_TODO,
        };
    }

    /**
     * @param array<string, list<IndustryProjectBomLine>> $componentsByParentId
     * @param array<string, string>                       $jobStatusByLineId
     */
    private function jobStatusOf(IndustryProjectBomLine $line, array $componentsByParentId, array &$jobStatusByLineId): string
    {
        $lineId = self::lineId($line);
        if (isset($jobStatusByLineId[$lineId])) {
            return $jobStatusByLineId[$lineId];
        }

        return $jobStatusByLineId[$lineId] = $this->computedJobStatusOf($line, $componentsByParentId, $jobStatusByLineId);
    }

    /**
     * @param array<string, list<IndustryProjectBomLine>> $componentsByParentId
     * @param array<string, string>                       $jobStatusByLineId
     */
    private function computedJobStatusOf(IndustryProjectBomLine $line, array $componentsByParentId, array &$jobStatusByLineId): string
    {
        $deliveredRuns = 0;
        $hasJobInProgress = false;
        foreach ($line->getJobMatches() as $link) {
            $linkStatus = $link->getStatus();
            if (CachedIndustryJob::STATUS_DELIVERED === $linkStatus) {
                $deliveredRuns += $link->getRuns();
            } elseif (\in_array($linkStatus, self::LINK_STATUSES_IN_PROGRESS, true)) {
                $hasJobInProgress = true;
            } elseif (!\in_array($linkStatus, CachedIndustryJob::STATUSES_WITHOUT_OUTPUT, true)) {
                return self::STATUS_UNKNOWN;
            }
        }

        $runs = $line->getRuns() ?? throw new \UnexpectedValueException(\sprintf('Job line %s has no runs.', self::lineId($line)));
        if ($deliveredRuns >= $runs) {
            return self::STATUS_DONE;
        }
        if ($hasJobInProgress || $deliveredRuns > 0) {
            return self::STATUS_ACTIVE;
        }

        foreach ($componentsByParentId[self::lineId($line)] ?? [] as $component) {
            if (self::STATUS_DONE !== $this->jobStatusOf($component, $componentsByParentId, $jobStatusByLineId)) {
                return self::STATUS_BLOCKED;
            }
        }

        return self::STATUS_TODO;
    }

    /**
     * @param list<IndustryProjectContribution> $approvedContributions
     * @return array{string, float|null}
     */
    private function costOf(array $approvedContributions): array
    {
        if ([] === $approvedContributions) {
            return [self::COST_NONE, null];
        }

        $realCost = 0.0;
        foreach ($approvedContributions as $contribution) {
            $value = $contribution->getEstimatedValue();
            if (null === $value) {
                return [self::COST_UNKNOWN, null];
            }
            $realCost += $value;
        }

        return [self::COST_KNOWN, $realCost];
    }

    /**
     * @param list<IndustryProjectContribution> $approvedContributions
     * @return list<array{participantId: string, characterName: string|null, type: string, quantity: int}>
     */
    private function suppliersOf(array $approvedContributions): array
    {
        $suppliers = [];
        foreach ($approvedContributions as $contribution) {
            $member = $contribution->getMember();
            $participantId = $member->getId()->toRfc4122();
            $type = $contribution->getType()->value;
            $key = $participantId.':'.$type;
            $suppliers[$key] ??= ['participantId' => $participantId, 'characterName' => PilotName::of($member->getUser()), 'type' => $type, 'quantity' => 0];
            $suppliers[$key]['quantity'] += $contribution->getQuantity();
        }

        return array_values($suppliers);
    }

    /**
     * @return list<array{esiJobId: int, status: string, runs: int, characterName: string|null, endDate: string|null, cost: float|null, facilityName: string|null}>
     */
    private function jobsOf(IndustryProjectBomLine $line): array
    {
        $jobs = [];
        foreach ($line->getJobMatches() as $link) {
            if (\in_array($link->getStatus(), CachedIndustryJob::STATUSES_WITHOUT_OUTPUT, true)) {
                continue;
            }
            $jobs[] = $this->jobOf($link);
        }

        return $jobs;
    }

    /**
     * @return array{esiJobId: int, status: string, runs: int, characterName: string|null, endDate: string|null, cost: float|null, facilityName: string|null}
     */
    private function jobOf(IndustryProjectJobMatch $link): array
    {
        return [
            'esiJobId' => $link->getEsiJobId(),
            'status' => $link->getStatus(),
            'runs' => $link->getRuns(),
            'characterName' => $link->getCharacterName(),
            'endDate' => $link->getEndDate()?->format(\DATE_ATOM),
            'cost' => $link->getCost(),
            'facilityName' => $link->getFacilityName(),
        ];
    }

    /**
     * @param list<array{facilityName: string|null}> $jobs
     * @return list<string>
     */
    private function structuresOf(array $jobs): array
    {
        $facilityNames = [];
        foreach ($jobs as $job) {
            if (null !== $job['facilityName']) {
                $facilityNames[$job['facilityName']] = $job['facilityName'];
            }
        }

        return array_values($facilityNames);
    }

    /**
     * @param array<string, string> $structureNamesById
     * @return array{name: string|null, status: string}|null
     */
    private function plannedStructureOf(IndustryProjectBomLine $line, array $structureNamesById): ?array
    {
        $status = $line->getPlannedStructureStatus();
        if (null === $status) {
            return null;
        }
        if (!\in_array($status, self::PLANNED_STRUCTURE_STATUSES, true)) {
            throw new \UnexpectedValueException(\sprintf('BOM line %s has the unknown planned structure status "%s".', self::lineId($line), $status));
        }

        $structureId = $line->getPlannedStructureId();
        if (null === $structureId || self::PLANNED_STRUCTURE_NOT_CONFIGURED === $status) {
            return null;
        }

        return ['name' => $structureNamesById[$structureId] ?? null, 'status' => $status];
    }

    /**
     * @param array<IndustryProjectSale> $sales
     */
    private function saleNode(IndustryProject $project, array $sales): ProjectChainNode
    {
        $soldQuantity = 0;
        $grossRevenue = 0.0;
        foreach ($sales as $sale) {
            $soldQuantity += $sale->getQuantity();
            $grossRevenue += $sale->getTotalPrice();
        }

        return new ProjectChainNode(
            self::SALE_NODE_ID,
            self::STAGE_SALE,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            match (true) {
                $project->isCompleted() => self::STATUS_DONE,
                [] !== $sales => self::STATUS_ACTIVE,
                default => self::STATUS_TODO,
            },
            self::COST_NONE,
            null,
            [],
            [],
            [],
            null,
            $soldQuantity,
            $grossRevenue,
        );
    }

    private static function lineId(IndustryProjectBomLine $line): string
    {
        return $line->getId()->toRfc4122();
    }
}
