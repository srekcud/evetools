<?php

declare(strict_types=1);

namespace App\Service\Industry\Skills;

use App\Entity\CachedCharacterSkill;
use App\Entity\CachedIndustryJob;
use App\Entity\Character;
use App\Entity\User;
use App\Enum\SkillEffect;
use App\Enum\SkillGainUnknownReason;
use App\Industry\Application\InventionSkillRequirements;
use App\Industry\Domain\InventionChance;
use App\Industry\Domain\SkillGain;
use App\Industry\Domain\SkillTimeModifier;
use App\Repository\CachedCharacterSkillRepository;
use App\Service\Industry\Revenue\IndustryRevenueReader;
use App\Service\Industry\SlotTrackerService;

class SkillGainEstimator
{
    private const int UNTRAINED_LEVEL = 0;
    private const int MAX_LEVEL = 5;
    private const int INVENTION_ACTIVITY_ID = 8;
    private const float SECONDS_PER_HOUR = 3600.0;
    private const float SECONDS_PER_DAY = 86400.0;
    private const float RATIO_ONLY_BASE_PROBABILITY = 0.01;
    private const array MANUFACTURING_ACTIVITY_IDS = [1];
    private const array REACTION_ACTIVITY_IDS = [9, 11];
    private const array TIME_SKILLS = [
        CachedCharacterSkill::SKILL_INDUSTRY => [SkillEffect::ManufacturingTime, SkillTimeModifier::INDUSTRY_REDUCTION_PER_LEVEL, self::MANUFACTURING_ACTIVITY_IDS],
        CachedCharacterSkill::SKILL_ADVANCED_INDUSTRY => [SkillEffect::ManufacturingTime, SkillTimeModifier::ADVANCED_INDUSTRY_REDUCTION_PER_LEVEL, self::MANUFACTURING_ACTIVITY_IDS],
        CachedCharacterSkill::SKILL_REACTIONS => [SkillEffect::ReactionTime, SkillTimeModifier::REACTIONS_REDUCTION_PER_LEVEL, self::REACTION_ACTIVITY_IDS],
    ];
    private const array SLOT_SKILLS = [
        CachedCharacterSkill::SKILL_MASS_PRODUCTION => [SkillEffect::ManufacturingSlot, 'manufacturing'],
        CachedCharacterSkill::SKILL_ADVANCED_MASS_PRODUCTION => [SkillEffect::ManufacturingSlot, 'manufacturing'],
        CachedCharacterSkill::SKILL_MASS_REACTIONS => [SkillEffect::ReactionSlot, 'reaction'],
        CachedCharacterSkill::SKILL_ADVANCED_REACTIONS => [SkillEffect::ReactionSlot, 'reaction'],
        CachedCharacterSkill::SKILL_LABORATORY_OPERATION => [SkillEffect::ScienceSlot, 'science'],
        CachedCharacterSkill::SKILL_ADVANCED_LABORATORY_OPERATION => [SkillEffect::ScienceSlot, 'science'],
    ];

    public function __construct(
        private readonly OwnProjectJobsLoader $jobsLoader,
        private readonly IndustryRevenueReader $revenueReader,
        private readonly CachedCharacterSkillRepository $skillRepository,
        private readonly InventionSkillRequirements $inventionRequirements,
        private readonly SkillTrainingEstimator $trainingEstimator,
    ) {
    }

    public function estimate(User $user, Character $character, \DateTimeImmutable $from, \DateTimeImmutable $to): SkillGainReport
    {
        $iskPerHour = $this->revenueReader->reportFor($user, $from, $to)->totals->iskPerHour;
        $projectJobs = $this->jobsLoader->loadFor($user, $character, $from, $to);
        $skills = $this->skillRepository->findAllSkillsForCharacter($character);

        $unknownReason = match (true) {
            $skills === [] => SkillGainUnknownReason::SkillsNotSynchronized,
            !$projectJobs->complete => SkillGainUnknownReason::ProjectJobsNotCached,
            $iskPerHour === null => SkillGainUnknownReason::IskPerHourUnknown,
            default => null,
        };

        $lines = [];
        foreach ($this->candidates($projectJobs->jobs, $skills) as $skillId => $candidate) {
            $level = isset($skills[$skillId]) ? $skills[$skillId]->getLevel() : self::UNTRAINED_LEVEL;
            if ($level >= self::MAX_LEVEL) {
                continue;
            }

            $hoursSaved = $unknownReason === null ? $candidate['hoursSaved']($level) : null;
            $lines[] = $this->line($character, $skillId, $level, $candidate['effect'], $hoursSaved, $iskPerHour, $unknownReason, $skills);
        }

        usort($lines, static fn (SkillGainLine $a, SkillGainLine $b): int => match (true) {
            $a->gainPerTrainingDay === null && $b->gainPerTrainingDay === null => 0,
            $a->gainPerTrainingDay === null => 1,
            $b->gainPerTrainingDay === null => -1,
            default => $b->gainPerTrainingDay <=> $a->gainPerTrainingDay,
        });

        return new SkillGainReport($iskPerHour, $lines);
    }

    /**
     * @param list<CachedIndustryJob>          $jobs
     * @param array<int, CachedCharacterSkill> $skills
     *
     * @return array<int, array{effect: SkillEffect, hoursSaved: \Closure(int): float}>
     */
    private function candidates(array $jobs, array $skills): array
    {
        $hoursByActivity = [];
        $hoursByCategory = [];
        foreach ($jobs as $job) {
            $hours = $this->hoursOf($job);
            $hoursByActivity[$job->getActivityId()] = ($hoursByActivity[$job->getActivityId()] ?? 0.0) + $hours;
            $category = SlotTrackerService::getSlotCategory($job->getActivityId());
            if ($category !== null) {
                $hoursByCategory[$category] = ($hoursByCategory[$category] ?? 0.0) + $hours;
            }
        }

        $candidates = [];
        foreach (self::TIME_SKILLS as $skillId => [$effect, $reductionPerLevel, $activityIds]) {
            $hours = array_sum(array_map(static fn (int $activityId): float => $hoursByActivity[$activityId] ?? 0.0, $activityIds));
            $candidates[$skillId] = [
                'effect' => $effect,
                'hoursSaved' => static fn (int $level): float => SkillGain::timeSavedHours($hours, $level, $reductionPerLevel),
            ];
        }

        $maxSlots = SlotTrackerService::calculateMaxSlots($skills);
        foreach (self::SLOT_SKILLS as $skillId => [$effect, $category]) {
            $averageSlotHours = SkillGain::averageSlotHours($hoursByCategory[$category] ?? 0.0, $maxSlots[$category]);
            $candidates[$skillId] = ['effect' => $effect, 'hoursSaved' => static fn (int $level): float => $averageSlotHours];
        }

        foreach ($this->inventionHoursSavedBySkill($jobs, $skills) as $skillId => $hoursSaved) {
            $candidates[$skillId] = ['effect' => SkillEffect::InventionChance, 'hoursSaved' => static fn (int $level): float => $hoursSaved];
        }

        return $candidates;
    }

    /**
     * @param list<CachedIndustryJob>          $jobs
     * @param array<int, CachedCharacterSkill> $skills
     *
     * @return array<int, float>
     */
    private function inventionHoursSavedBySkill(array $jobs, array $skills): array
    {
        $hoursSavedBySkill = [];
        foreach ($jobs as $job) {
            if ($job->getActivityId() !== self::INVENTION_ACTIVITY_ID) {
                continue;
            }

            $requirement = $this->inventionRequirements->of($job->getBlueprintTypeId());
            $requiredSkillIds = [$requirement->scienceSkillTypeIds[0] ?? null, $requirement->scienceSkillTypeIds[1] ?? null, $requirement->encryptionSkillTypeId];
            $levels = array_map(
                static fn (?int $skillId): int => $skillId !== null && isset($skills[$skillId]) ? $skills[$skillId]->getLevel() : self::UNTRAINED_LEVEL,
                $requiredSkillIds,
            );
            $current = InventionChance::of(self::RATIO_ONLY_BASE_PROBABILITY, $levels[0], $levels[1], $levels[2], null);

            foreach ($requiredSkillIds as $position => $skillId) {
                if ($skillId === null) {
                    continue;
                }
                $hoursSavedBySkill[$skillId] ??= 0.0;
                if ($levels[$position] >= self::MAX_LEVEL) {
                    continue;
                }
                $raisedLevels = $levels;
                ++$raisedLevels[$position];
                $raised = InventionChance::of(self::RATIO_ONLY_BASE_PROBABILITY, $raisedLevels[0], $raisedLevels[1], $raisedLevels[2], null);
                $hoursSavedBySkill[$skillId] += SkillGain::inventionSavedHours($this->hoursOf($job), $current, $raised);
            }
        }

        return $hoursSavedBySkill;
    }

    /**
     * @param array<int, CachedCharacterSkill> $skills
     */
    private function line(
        Character $character,
        int $skillId,
        int $level,
        SkillEffect $effect,
        ?float $hoursSaved,
        ?float $iskPerHour,
        ?SkillGainUnknownReason $unknownReason,
        array $skills,
    ): SkillGainLine {
        $monthlyGain = $hoursSaved !== null && $iskPerHour !== null ? $hoursSaved * $iskPerHour : null;
        $training = $this->trainingEstimator->nextLevel($character, $skillId, $level, $this->skillPointsOf($skillId, $skills));

        return new SkillGainLine(
            $skillId,
            $level,
            $level + 1,
            $effect,
            $hoursSaved,
            $monthlyGain,
            $unknownReason,
            $training->seconds,
            $training->unknownReason,
            $monthlyGain !== null && $training->seconds !== null ? $monthlyGain / ($training->seconds / self::SECONDS_PER_DAY) : null,
        );
    }

    /**
     * @param array<int, CachedCharacterSkill> $skills
     */
    private function skillPointsOf(int $skillId, array $skills): ?int
    {
        if ($skills === []) {
            return null;
        }
        if (!isset($skills[$skillId])) {
            return 0;
        }

        return $skills[$skillId]->isManual() ? null : $skills[$skillId]->getSkillPoints();
    }

    private function hoursOf(CachedIndustryJob $job): float
    {
        return ($job->getEndDate()->getTimestamp() - $job->getStartDate()->getTimestamp()) / self::SECONDS_PER_HOUR;
    }
}
