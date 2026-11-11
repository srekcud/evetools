<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

use App\Entity\CachedIndustryJob;
use App\Entity\IndustryProjectBomLine;

final readonly class ChainMaterialSupply
{
    private const string GROUP_COMPONENT = 'component';
    private const array JOB_GROUPS = [self::GROUP_COMPONENT, 'final'];

    /**
     * @param array<string, bool> $suppliedByLineId
     * @param array<int, int>     $firstConsumerLevelByTypeId
     */
    private function __construct(private array $suppliedByLineId, private array $firstConsumerLevelByTypeId)
    {
    }

    /**
     * @param array<IndustryProjectBomLine>       $bomLines
     * @param array<int, int>                     $levelByTypeId
     * @param array<string, array<int, int>>|null $jobMaterialNeeds null when the plan could not be computed
     */
    public static function allocate(array $bomLines, array $levelByTypeId, ?array $jobMaterialNeeds): self
    {
        if (null === $jobMaterialNeeds) {
            return new self([], []);
        }

        $remainingByMaterialTypeId = [];
        $jobLines = [];
        foreach ($bomLines as $line) {
            if (!$line->isJob()) {
                $remainingByMaterialTypeId[$line->getTypeId()] ??= 0;
                $remainingByMaterialTypeId[$line->getTypeId()] += $line->getFulfilledQuantity();
            } elseif (\in_array($line->getJobGroup(), self::JOB_GROUPS, true)) {
                $remainingByMaterialTypeId[$line->getTypeId()] ??= 0;
                $remainingByMaterialTypeId[$line->getTypeId()] += $line->getFulfilledQuantity() + self::deliveredProductionOf($line);
                if (isset($jobMaterialNeeds[$line->getId()->toRfc4122()])) {
                    $jobLines[] = $line;
                }
            }
        }

        usort($jobLines, static fn (IndustryProjectBomLine $left, IndustryProjectBomLine $right): int => self::levelOf($left, $levelByTypeId) <=> self::levelOf($right, $levelByTypeId)
            ?: $left->getTypeName() <=> $right->getTypeName()
            ?: $left->getId()->toRfc4122() <=> $right->getId()->toRfc4122());

        $suppliedByLineId = [];
        $firstConsumerLevelByTypeId = [];
        foreach ($jobLines as $line) {
            $lineId = $line->getId()->toRfc4122();
            $supplied = true;
            foreach ($jobMaterialNeeds[$lineId] as $materialTypeId => $neededQuantity) {
                if (!isset($remainingByMaterialTypeId[$materialTypeId])) {
                    continue;
                }
                $grantedQuantity = min($neededQuantity, $remainingByMaterialTypeId[$materialTypeId]);
                $remainingByMaterialTypeId[$materialTypeId] -= $grantedQuantity;
                $supplied = $supplied && $grantedQuantity >= $neededQuantity;
                $firstConsumerLevelByTypeId[$materialTypeId] ??= self::levelOf($line, $levelByTypeId);
            }
            $suppliedByLineId[$lineId] = $supplied;
        }

        return new self($suppliedByLineId, $firstConsumerLevelByTypeId);
    }

    public function isSupplied(string $lineId): ?bool
    {
        return $this->suppliedByLineId[$lineId] ?? null;
    }

    public function firstConsumerLevel(int $materialTypeId): ?int
    {
        return $this->firstConsumerLevelByTypeId[$materialTypeId] ?? null;
    }

    private static function deliveredProductionOf(IndustryProjectBomLine $line): int
    {
        if (self::GROUP_COMPONENT !== $line->getJobGroup()) {
            return 0;
        }

        $runs = $line->getRuns() ?? throw new \UnexpectedValueException(\sprintf('Job line %s has no runs.', $line->getId()->toRfc4122()));
        if (0 === $runs) {
            return 0;
        }

        $deliveredRuns = 0;
        foreach ($line->getJobMatches() as $jobMatch) {
            if (CachedIndustryJob::STATUS_DELIVERED === $jobMatch->getStatus()) {
                $deliveredRuns += $jobMatch->getRuns();
            }
        }

        return intdiv($line->getRemainingQuantity() * min($deliveredRuns, $runs), $runs);
    }

    /**
     * @param array<int, int> $levelByTypeId
     */
    private static function levelOf(IndustryProjectBomLine $line, array $levelByTypeId): int
    {
        return $levelByTypeId[$line->getTypeId()]
            ?? throw new \UnexpectedValueException(\sprintf('Job line %s has no manufacturing level.', $line->getId()->toRfc4122()));
    }
}
