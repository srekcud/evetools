<?php

declare(strict_types=1);

namespace App\Service\MiningSite;

use App\Enum\MinersEstimateStatus;

final readonly class MinersNeededCalculator
{
    private const int MIN_SITES = 3;
    private const int FIRST_CREW_SIZE = 4;
    private const int LAST_CREW_SIZE = 9;
    private const float SECONDS_PER_MINUTE = 60.0;

    public function __construct(private EveDowntime $downtime = new EveDowntime())
    {
    }

    /**
     * @param list<SiteLifespanSample> $samples
     */
    public function calculate(array $samples, \DateTimeImmutable $start): MinersNeeded
    {
        $workloads = array_values(array_filter(array_map(static fn (SiteLifespanSample $sample): ?float => $sample->workMinerMinutes(), $samples), static fn (?float $workload): bool => $workload !== null));
        if (\count($workloads) < self::MIN_SITES) {
            return new MinersNeeded(MinersEstimateStatus::InsufficientHistory, \count($workloads), null, null, null, null, []);
        }

        $meanWorkload = array_sum($workloads) / \count($workloads);
        $deadline = $this->downtime->nextAfter($start);
        $availableMinutes = ($deadline->getTimestamp() - $start->getTimestamp()) / self::SECONDS_PER_MINUTE;

        return new MinersNeeded(
            MinersEstimateStatus::Estimated,
            \count($workloads),
            $meanWorkload,
            max(1, (int) ceil($meanWorkload / $availableMinutes)),
            $deadline,
            $availableMinutes,
            array_map(
                static fn (int $miners): array => ['miners' => $miners, 'minutes' => $meanWorkload / $miners, 'beforeDeadline' => $meanWorkload / $miners <= $availableMinutes],
                range(self::FIRST_CREW_SIZE, self::LAST_CREW_SIZE),
            ),
        );
    }
}
