<?php

declare(strict_types=1);

namespace App\Service\Industry;

use App\Entity\CachedCharacterSkill;
use App\Entity\CachedIndustryJob;
use App\Entity\Character;
use App\Entity\User;
use App\Repository\CachedAssetRepository;
use App\Repository\CachedCharacterSkillRepository;
use App\Repository\CachedIndustryJobRepository;
use App\Repository\CharacterRepository;
use App\Repository\IndustryStockpileTargetRepository;
use App\Service\TypeNameResolver;

class SlotTrackerService
{
    /** ESI activity ID to human-readable activity type */
    private const ACTIVITY_TYPE_MAP = [
        1 => 'manufacturing',
        3 => 'research_te',
        4 => 'research_me',
        5 => 'copying',
        7 => 'reverse_engineering',
        8 => 'invention',
        9 => 'reaction',
        11 => 'reaction',
    ];

    /** ESI activity ID to slot category (manufacturing, reaction, science) */
    private const ACTIVITY_SLOT_MAP = [
        1 => 'manufacturing',
        3 => 'science',
        4 => 'science',
        5 => 'science',
        7 => 'science',
        8 => 'science',
        9 => 'reaction',
        11 => 'reaction',
    ];

    /** Stages eligible for free-slot suggestions per slot category */
    private const SUGGESTION_STAGES = [
        'manufacturing' => ['component', 'final_product'],
        'reaction' => ['intermediate'],
        'science' => ['final_product'],
    ];

    public function __construct(
        private readonly CharacterRepository $characterRepository,
        private readonly CachedIndustryJobRepository $jobRepository,
        private readonly CachedCharacterSkillRepository $skillRepository,
        private readonly IndustryCalculationService $calculationService,
        private readonly TypeNameResolver $typeNameResolver,
        private readonly IndustryStockpileTargetRepository $stockpileTargetRepository,
        private readonly CachedAssetRepository $assetRepository,
    ) {
    }

    /**
     * @return array{globalKpis: array<string, array{used: int, max: int, percent: float}>, characters: list<array<string, mixed>>, timeline: list<array<string, mixed>>, skillsMayBeStale: bool}
     */
    public function getSlotStatus(User $user): array
    {
        $characters = $this->characterRepository->findByUser($user);
        $now = new \DateTimeImmutable();

        // Collect all product type IDs across all characters for batch name resolution
        $allProductTypeIds = [];
        $characterData = [];

        foreach ($characters as $character) {
            $jobs = $this->jobRepository->findActiveJobsByCharacter($character);
            foreach ($jobs as $job) {
                $allProductTypeIds[] = $job->getProductTypeId();
            }
            $characterData[] = ['character' => $character, 'jobs' => $jobs];
        }

        $typeNames = $this->typeNameResolver->resolveMany(array_unique($allProductTypeIds));

        // Stockpile targets for suggestions
        $stockpileTargets = $this->stockpileTargetRepository->findByUser($user);
        $assetQuantities = !empty($stockpileTargets)
            ? $this->assetRepository->getAggregatedQuantitiesByUser($user)
            : [];

        // Build per-character data
        $globalUsed = ['manufacturing' => 0, 'reaction' => 0, 'science' => 0];
        $globalMax = ['manufacturing' => 0, 'reaction' => 0, 'science' => 0];
        $allTimelineJobs = [];
        $charactersOutput = [];

        foreach ($characterData as $data) {
            /** @var Character $character */
            $character = $data['character'];
            /** @var CachedIndustryJob[] $jobs */
            $jobs = $data['jobs'];

            $skills = $this->skillRepository->findSlotSkillsForCharacter($character);
            $maxSlots = self::calculateMaxSlots($skills);

            // Count used slots per category
            // Exclude active jobs whose endDate is in the past — they are
            // effectively completed and no longer occupy a slot in-game
            $usedSlots = ['manufacturing' => 0, 'reaction' => 0, 'science' => 0];
            foreach ($jobs as $job) {
                if ($job->getStatus() === 'active' && $job->getEndDate() <= $now) {
                    continue;
                }
                $slotCategory = self::getSlotCategory($job->getActivityId());
                if ($slotCategory !== null) {
                    $usedSlots[$slotCategory]++;
                }
            }

            // Build job entries — only include actively running jobs (not ready/delivered/completed)
            $jobEntries = [];
            foreach ($jobs as $job) {
                if ($job->getStatus() !== 'active') {
                    continue;
                }

                // Skip jobs whose end_date is in the past — they are effectively
                // completed but ESI has not yet flipped their status to "ready"
                if ($job->getEndDate() <= $now) {
                    continue;
                }

                $activityType = self::getActivityType($job->getActivityId());
                $progress = self::calculateProgress($job->getStartDate(), $job->getEndDate(), $now);
                $timeLeftSeconds = self::calculateTimeLeft($job->getEndDate(), $now);

                $jobEntry = [
                    'jobId' => $job->getJobId(),
                    'productTypeId' => $job->getProductTypeId(),
                    'productTypeName' => $typeNames[$job->getProductTypeId()] ?? "Type #{$job->getProductTypeId()}",
                    'activityType' => $activityType ?? 'unknown',
                    'runs' => $job->getRuns(),
                    'progress' => $progress,
                    'timeLeftSeconds' => $timeLeftSeconds,
                    'facilityName' => $job->getStationId() !== null ? $this->calculationService->resolveFacilityName($job->getStationId()) : null,
                    'startDate' => $job->getStartDate()->format(\DateTimeInterface::ATOM),
                    'endDate' => $job->getEndDate()->format(\DateTimeInterface::ATOM),
                ];
                $jobEntries[] = $jobEntry;

                $allTimelineJobs[] = [
                    'jobId' => $job->getJobId(),
                    'characterName' => $character->getName(),
                    'productTypeName' => $jobEntry['productTypeName'],
                    'activityType' => $jobEntry['activityType'],
                    'runs' => $job->getRuns(),
                    'timeLeftSeconds' => $timeLeftSeconds,
                    'endDate' => $jobEntry['endDate'],
                ];
            }

            // Sort jobs by timeLeftSeconds ascending
            usort($jobEntries, static fn (array $a, array $b) => $a['timeLeftSeconds'] <=> $b['timeLeftSeconds']);

            // Free slots with suggestions
            $freeSlots = $this->buildFreeSlots($usedSlots, $maxSlots, $stockpileTargets, $assetQuantities, $typeNames);

            // Aggregate globals
            foreach (['manufacturing', 'reaction', 'science'] as $cat) {
                $globalUsed[$cat] += $usedSlots[$cat];
                $globalMax[$cat] += $maxSlots[$cat];
            }

            $charactersOutput[] = [
                'characterId' => $character->getEveCharacterId(),
                'characterName' => $character->getName(),
                'isMain' => $character->isMain(),
                'slots' => [
                    'manufacturing' => ['used' => $usedSlots['manufacturing'], 'max' => $maxSlots['manufacturing']],
                    'reaction' => ['used' => $usedSlots['reaction'], 'max' => $maxSlots['reaction']],
                    'science' => ['used' => $usedSlots['science'], 'max' => $maxSlots['science']],
                ],
                'jobs' => $jobEntries,
                'freeSlots' => $freeSlots,
            ];
        }

        // Sort characters: main first, then alphabetically
        usort($charactersOutput, static function (array $a, array $b): int {
            if ($a['isMain'] !== $b['isMain']) {
                return $b['isMain'] <=> $a['isMain'];
            }

            return $a['characterName'] <=> $b['characterName'];
        });

        // Sort timeline by endDate ascending
        usort($allTimelineJobs, static fn (array $a, array $b) => $a['endDate'] <=> $b['endDate']);

        // Detect stale skills: in-game you can never have more active jobs
        // than available slots, so used > max means skill data is missing/outdated
        $skillsMayBeStale = false;
        foreach (['manufacturing', 'reaction', 'science'] as $cat) {
            if ($globalUsed[$cat] > $globalMax[$cat]) {
                $skillsMayBeStale = true;
                // Adjust max to at least used so percent doesn't exceed 100%
                $globalMax[$cat] = $globalUsed[$cat];
            }
        }

        // Build global KPIs
        $globalKpis = [];
        foreach (['manufacturing', 'reaction', 'science'] as $cat) {
            $max = $globalMax[$cat];
            $used = $globalUsed[$cat];
            $globalKpis[$cat] = [
                'used' => $used,
                'max' => $max,
                'percent' => $max > 0 ? round(($used / $max) * 100, 1) : 0.0,
            ];
        }

        return [
            'globalKpis' => $globalKpis,
            'characters' => $charactersOutput,
            'timeline' => $allTimelineJobs,
            'skillsMayBeStale' => $skillsMayBeStale,
        ];
    }

    /**
     * Calculate max slots from skill levels.
     * Base 1 + skill level + advanced skill level per category.
     *
     * @param array<int, CachedCharacterSkill> $skills indexed by skill ID
     * @return array{manufacturing: int, reaction: int, science: int}
     */
    public static function calculateMaxSlots(array $skills): array
    {
        $getLevel = static fn (int $skillId): int => isset($skills[$skillId]) ? $skills[$skillId]->getLevel() : 0;

        return [
            'manufacturing' => 1 + $getLevel(CachedCharacterSkill::SKILL_MASS_PRODUCTION) + $getLevel(CachedCharacterSkill::SKILL_ADVANCED_MASS_PRODUCTION),
            'reaction' => 1 + $getLevel(CachedCharacterSkill::SKILL_MASS_REACTIONS) + $getLevel(CachedCharacterSkill::SKILL_ADVANCED_REACTIONS),
            'science' => 1 + $getLevel(CachedCharacterSkill::SKILL_LABORATORY_OPERATION) + $getLevel(CachedCharacterSkill::SKILL_ADVANCED_LABORATORY_OPERATION),
        ];
    }

    /**
     * Map an ESI activity ID to a slot category.
     */
    public static function getSlotCategory(int $activityId): ?string
    {
        return self::ACTIVITY_SLOT_MAP[$activityId] ?? null;
    }

    /**
     * Map an ESI activity ID to a human-readable activity type.
     */
    public static function getActivityType(int $activityId): ?string
    {
        return self::ACTIVITY_TYPE_MAP[$activityId] ?? null;
    }

    /**
     * Calculate progress percentage from start/end/now.
     */
    public static function calculateProgress(\DateTimeImmutable $startDate, \DateTimeImmutable $endDate, \DateTimeImmutable $now): float
    {
        $totalDuration = $endDate->getTimestamp() - $startDate->getTimestamp();
        $elapsed = $now->getTimestamp() - $startDate->getTimestamp();

        return $totalDuration > 0
            ? round(min(100.0, max(0.0, ($elapsed / $totalDuration) * 100)), 1)
            : 100.0;
    }

    /**
     * Calculate time left in seconds.
     */
    public static function calculateTimeLeft(\DateTimeImmutable $endDate, \DateTimeImmutable $now): int
    {
        return max(0, $endDate->getTimestamp() - $now->getTimestamp());
    }

    /**
     * Build free slot entries with suggestions from stockpile targets.
     *
     * @param array{manufacturing: int, reaction: int, science: int} $usedSlots
     * @param array{manufacturing: int, reaction: int, science: int} $maxSlots
     * @param \App\Entity\IndustryStockpileTarget[] $stockpileTargets
     * @param array<int, int> $assetQuantities
     * @param array<int, string> $typeNames
     * @return list<array{activityType: string, count: int, suggestion: array{typeId: int, typeName: string, reason: string}|null}>
     */
    private function buildFreeSlots(array $usedSlots, array $maxSlots, array $stockpileTargets, array $assetQuantities, array $typeNames): array
    {
        $freeSlots = [];

        foreach (['manufacturing', 'reaction', 'science'] as $category) {
            $freeCount = max(0, $maxSlots[$category] - $usedSlots[$category]);
            if ($freeCount === 0) {
                continue;
            }

            $suggestion = $this->findSuggestion($category, $stockpileTargets, $assetQuantities, $typeNames);

            $freeSlots[] = [
                'activityType' => $category,
                'count' => $freeCount,
                'suggestion' => $suggestion,
            ];
        }

        return $freeSlots;
    }

    /**
     * Find the best stockpile suggestion for a given slot category.
     *
     * @param \App\Entity\IndustryStockpileTarget[] $stockpileTargets
     * @param array<int, int> $assetQuantities
     * @param array<int, string> $typeNames
     * @return array{typeId: int, typeName: string, reason: string}|null
     */
    private function findSuggestion(string $category, array $stockpileTargets, array $assetQuantities, array $typeNames): ?array
    {
        $eligibleStages = self::SUGGESTION_STAGES[$category] ?? [];
        if (empty($eligibleStages)) {
            return null;
        }

        $worstCoverage = null;
        $worstTarget = null;

        foreach ($stockpileTargets as $target) {
            if (!in_array($target->getStage(), $eligibleStages, true)) {
                continue;
            }

            $stock = $assetQuantities[$target->getTypeId()] ?? 0;
            $targetQty = $target->getTargetQuantity();
            $coverage = $targetQty > 0 ? ($stock / $targetQty) * 100 : 100.0;

            // Already fully stocked, skip
            if ($coverage >= 100.0) {
                continue;
            }

            if ($worstCoverage === null || $coverage < $worstCoverage) {
                $worstCoverage = $coverage;
                $worstTarget = $target;
            }
        }

        if ($worstTarget === null) {
            return null;
        }

        return [
            'typeId' => $worstTarget->getTypeId(),
            'typeName' => $typeNames[$worstTarget->getTypeId()] ?? $worstTarget->getTypeName(),
            'reason' => sprintf('stockpile at %d%%', (int) round($worstCoverage)),
        ];
    }
}
