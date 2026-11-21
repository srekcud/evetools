<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

use App\Entity\CachedIndustryJob;
use App\Entity\IndustryProjectBomLine;

final readonly class StartedJobConsumption
{
    /**
     * @param array<int, int> $consumedByTypeId
     */
    private function __construct(private array $consumedByTypeId, private bool $unknown)
    {
    }

    public static function none(): self
    {
        return new self([], false);
    }

    public static function unknown(): self
    {
        return new self([], true);
    }

    /**
     * @param array<\App\Entity\IndustryProjectBomLine> $jobLines
     * @param array<string, array<int, int>>|null $jobMaterialNeeds
     */
    public static function of(array $jobLines, ?array $jobMaterialNeeds): self
    {
        $consumedByTypeId = [];
        foreach ($jobLines as $line) {
            $startedRuns = self::startedRunsOf($line);
            if (0 === $startedRuns) {
                continue;
            }

            $lineRuns = $line->getRuns() ?? throw new \UnexpectedValueException(\sprintf('Job line %s has no runs.', $line->getId()->toRfc4122()));
            if (0 === $lineRuns) {
                continue;
            }

            $lineNeeds = $jobMaterialNeeds[$line->getId()->toRfc4122()] ?? null;
            if (null === $lineNeeds) {
                return self::unknown();
            }

            foreach ($lineNeeds as $typeId => $neededQuantity) {
                $consumedByTypeId[$typeId] = ($consumedByTypeId[$typeId] ?? 0) + intdiv($neededQuantity * min($startedRuns, $lineRuns), $lineRuns);
            }
        }

        return new self($consumedByTypeId, false);
    }

    private static function startedRunsOf(IndustryProjectBomLine $line): int
    {
        $startedRuns = 0;
        foreach ($line->getJobMatches() as $jobMatch) {
            if (!\in_array($jobMatch->getStatus(), CachedIndustryJob::STATUSES_WITHOUT_OUTPUT, true)) {
                $startedRuns += $jobMatch->getRuns();
            }
        }

        return $startedRuns;
    }

    public function isUnknown(): bool
    {
        return $this->unknown;
    }

    public function consumedOf(int $typeId): int
    {
        if ($this->unknown) {
            throw new \LogicException('The consumption of the started jobs is unknown.');
        }

        return $this->consumedByTypeId[$typeId] ?? 0;
    }
}
