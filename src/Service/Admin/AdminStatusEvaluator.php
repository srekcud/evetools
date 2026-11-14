<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\Dto\AdminStatus;
use App\Dto\AdminStatusRule;

class AdminStatusEvaluator
{
    public const ASYNC_QUEUE_DEGRADED_DEPTH = 1000;
    public const ASYNC_QUEUE_DOWN_DEPTH = 10000;
    public const FAILED_MESSAGES_THRESHOLD = 0;
    public const IN_FLIGHT_MESSAGE_DEGRADED_SECONDS = 3600;

    private const LEVEL_OK = 'ok';
    private const SEVERITY_DEGRADED = 'degraded';
    private const SEVERITY_DOWN = 'down';
    private const SECTION_SCHEDULER_HEALTH = 'scheduler-health';
    private const SECTION_MAINTENANCE = 'maintenance';
    private const SECTION_KPIS = 'kpis';
    private const HEALTH_STALE = 'stale';
    private const STATUS_ERROR = 'error';

    /** @param list<array<string, mixed>> $schedulerHealth */
    public function evaluate(array $schedulerHealth, int $usersWithInvalidAuth, ?int $asyncDepth, ?int $failedDepth, ?int $oldestRunningSeconds): AdminStatus
    {
        $scheduledEntries = array_filter($schedulerHealth, static fn (array $entry): bool => $entry['on_demand'] !== true);

        $rules = array_values(array_filter([
            $this->countRule(
                'scheduler_stale',
                self::SECTION_SCHEDULER_HEALTH,
                count(array_filter($scheduledEntries, static fn (array $entry): bool => $entry['health'] === self::HEALTH_STALE)),
            ),
            $this->countRule(
                'scheduler_error',
                self::SECTION_SCHEDULER_HEALTH,
                count(array_filter($scheduledEntries, static fn (array $entry): bool => $entry['status'] === self::STATUS_ERROR)),
            ),
            $this->asyncQueueRule($asyncDepth),
            $failedDepth === null
                ? new AdminStatusRule('failed_queue_unreachable', self::SEVERITY_DEGRADED, 0, null, self::SECTION_MAINTENANCE)
                : $this->countRule('failed_messages', self::SECTION_MAINTENANCE, $failedDepth, self::FAILED_MESSAGES_THRESHOLD),
            $this->inFlightMessageRule($oldestRunningSeconds),
            $this->countRule('tokens_invalid', self::SECTION_KPIS, $usersWithInvalidAuth),
        ]));

        return new AdminStatus($this->levelOf($rules), $rules);
    }

    private function countRule(string $key, string $section, int $count, ?int $threshold = null): ?AdminStatusRule
    {
        if ($count <= ($threshold ?? 0)) {
            return null;
        }

        return new AdminStatusRule($key, self::SEVERITY_DEGRADED, $count, $threshold, $section);
    }

    private function asyncQueueRule(?int $asyncDepth): ?AdminStatusRule
    {
        if ($asyncDepth === null) {
            return new AdminStatusRule('async_queue_unreachable', self::SEVERITY_DOWN, 0, null, self::SECTION_MAINTENANCE);
        }

        if ($asyncDepth > self::ASYNC_QUEUE_DOWN_DEPTH) {
            return new AdminStatusRule('async_queue_backlog', self::SEVERITY_DOWN, $asyncDepth, self::ASYNC_QUEUE_DOWN_DEPTH, self::SECTION_MAINTENANCE);
        }

        if ($asyncDepth > self::ASYNC_QUEUE_DEGRADED_DEPTH) {
            return new AdminStatusRule('async_queue_backlog', self::SEVERITY_DEGRADED, $asyncDepth, self::ASYNC_QUEUE_DEGRADED_DEPTH, self::SECTION_MAINTENANCE);
        }

        return null;
    }

    private function inFlightMessageRule(?int $oldestRunningSeconds): ?AdminStatusRule
    {
        if ($oldestRunningSeconds === null || $oldestRunningSeconds <= self::IN_FLIGHT_MESSAGE_DEGRADED_SECONDS) {
            return null;
        }

        return new AdminStatusRule('message_unacked_too_long', self::SEVERITY_DEGRADED, $oldestRunningSeconds, self::IN_FLIGHT_MESSAGE_DEGRADED_SECONDS, self::SECTION_MAINTENANCE);
    }

    /** @param list<AdminStatusRule> $rules */
    private function levelOf(array $rules): string
    {
        $severities = array_map(static fn (AdminStatusRule $rule): string => $rule->severity, $rules);

        return match (true) {
            in_array(self::SEVERITY_DOWN, $severities, true) => self::SEVERITY_DOWN,
            $severities !== [] => self::SEVERITY_DEGRADED,
            default => self::LEVEL_OK,
        };
    }
}
