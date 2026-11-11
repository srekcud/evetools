<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

use App\Entity\CachedCharacterSkill;
use App\Entity\CachedIndustryJob;
use App\Entity\Character;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;
use App\Enum\IndustryActivityType;
use App\Industry\Domain\ActivityKind;
use App\Industry\Application\PlannedJobTimeModifier;
use App\Industry\Application\UserProductionStructures;
use App\Industry\Domain\BlueprintCatalog;
use App\Industry\Domain\Multiplier;
use App\Repository\CachedCharacterSkillRepository;
use App\Repository\CachedIndustryJobRepository;
use App\Repository\CharacterRepository;
use App\Repository\IndustryProjectBomLineRepository;
use App\Repository\Sde\IndustryActivityProductRepository;
use App\Repository\Sde\IndustryActivitySkillRepository;
use App\Service\Industry\SlotTrackerService;

final readonly class ProjectPlanningInputLoader
{
    private const string ACTIVITY_MANUFACTURING = 'manufacturing';
    private const string ACTIVITY_REACTION = 'reaction';

    private const array TIME_SKILL_IDS_COVERED_BY_THE_JOB_DURATION = [
        CachedCharacterSkill::SKILL_INDUSTRY,
        CachedCharacterSkill::SKILL_ADVANCED_INDUSTRY,
    ];

    public function __construct(
        private IndustryProjectBomLineRepository $bomLineRepository,
        private CachedIndustryJobRepository $cachedJobRepository,
        private CharacterRepository $characterRepository,
        private CachedCharacterSkillRepository $skillRepository,
        private BlueprintCatalog $blueprintCatalog,
        private IndustryActivityProductRepository $activityProductRepository,
        private IndustryActivitySkillRepository $activitySkillRepository,
        private UserProductionStructures $userStructures,
        private PlannedJobTimeModifier $plannedJobTimeModifier,
    ) {
    }

    public function load(IndustryProject $project): ProjectPlanningInput
    {
        $bomLines = $this->bomLineRepository->findBy(['project' => $project]);
        $this->bomLineRepository->loadJobMatchesOfProject($project);

        $jobLines = array_values(array_filter($bomLines, static fn (IndustryProjectBomLine $line): bool => $line->isManufacturingOrReactionJob() && !$line->isCoveredByStock()));
        $cachedJobsById = $this->cachedJobRepository->findByJobIds($this->linkedJobIdsOf($jobLines));
        $scienceSkillIdsByLineId = $this->scienceSkillIdsByLineId($jobLines);
        $ownerId = $project->getOwner()->getId() ?? throw new \LogicException('A project owner without id has no configured structure.');
        $ownerStructures = $this->userStructures->configuredBy($ownerId->toRfc4122());

        $lines = [];
        foreach ($jobLines as $line) {
            $lineId = $line->getId()->toRfc4122();
            $activity = $this->activityOf($line);
            $structureTimeModifier = $this->plannedJobTimeModifier->of(
                $line->getPlannedStructureId(),
                $ownerStructures,
                $line->getTypeId(),
                self::ACTIVITY_REACTION === $activity ? ActivityKind::Reaction : ActivityKind::Manufacturing,
            );
            $lines[] = new PlanningLine(
                $lineId,
                $line->getTypeId(),
                $line->getTypeName(),
                $activity,
                $line->getRuns() ?? throw new \UnexpectedValueException(\sprintf('Job line %s has no runs.', $lineId)),
                $line->getTeLevel(),
                $line->getParentTypeId(),
                $this->baseTimeSecondsOf($line),
                $scienceSkillIdsByLineId[$lineId] ?? [],
                $this->linkedJobsOf($line, $cachedJobsById),
                $structureTimeModifier ?? Multiplier::one(),
                null !== $structureTimeModifier,
            );
        }

        $materials = [];
        foreach ($bomLines as $line) {
            if (!$line->isJob()) {
                $materials[] = new PlanningMaterial($line->getTypeName(), $line->getRequiredQuantity(), $line->getFulfilledQuantity());
            }
        }

        return new ProjectPlanningInput($project->getCreatedAt(), $lines, $materials, $this->charactersOf($project));
    }

    /**
     * @param list<IndustryProjectBomLine> $jobLines
     *
     * @return list<int>
     */
    private function linkedJobIdsOf(array $jobLines): array
    {
        $jobIds = [];
        foreach ($jobLines as $line) {
            foreach ($line->getJobMatches() as $match) {
                $jobIds[] = $match->getEsiJobId();
            }
        }

        return array_values(array_unique($jobIds));
    }

    /**
     * @return 'manufacturing'|'reaction'
     */
    private function activityOf(IndustryProjectBomLine $line): string
    {
        return match ($line->getActivityType()) {
            self::ACTIVITY_MANUFACTURING => self::ACTIVITY_MANUFACTURING,
            self::ACTIVITY_REACTION => self::ACTIVITY_REACTION,
            default => throw new \UnexpectedValueException(\sprintf('Job line %s has the unexpected activity "%s".', $line->getId()->toRfc4122(), (string) $line->getActivityType())),
        };
    }

    private function baseTimeSecondsOf(IndustryProjectBomLine $line): ?int
    {
        try {
            $recipe = $this->blueprintCatalog->recipeFor($line->getTypeId());
        } catch (\UnexpectedValueException) {
            return null;
        }

        $expectedActivity = self::ACTIVITY_REACTION === $line->getActivityType() ? ActivityKind::Reaction : ActivityKind::Manufacturing;

        return null !== $recipe && $recipe->activity === $expectedActivity ? $recipe->baseTimeSeconds : null;
    }

    /**
     * @param list<IndustryProjectBomLine> $jobLines
     *
     * @return array<string, list<int>> line id => science skill ids of the producer blueprint
     */
    private function scienceSkillIdsByLineId(array $jobLines): array
    {
        $blueprintIdByLineId = [];
        foreach ($jobLines as $line) {
            if (self::ACTIVITY_MANUFACTURING !== $line->getActivityType()) {
                continue;
            }
            $producer = $this->activityProductRepository->findBlueprintForProduct($line->getTypeId(), IndustryActivityType::Manufacturing->value);
            if (null !== $producer) {
                $blueprintIdByLineId[$line->getId()->toRfc4122()] = $producer->getTypeId();
            }
        }

        $requiredSkillsByBlueprintId = $this->activitySkillRepository->findSkillsForBlueprints(
            array_values(array_unique($blueprintIdByLineId)),
            IndustryActivityType::Manufacturing->value,
        );

        $scienceSkillIdsByLineId = [];
        foreach ($blueprintIdByLineId as $lineId => $blueprintTypeId) {
            $scienceSkillIds = [];
            foreach ($requiredSkillsByBlueprintId[$blueprintTypeId] ?? [] as $requiredSkill) {
                if (!\in_array($requiredSkill['skillId'], self::TIME_SKILL_IDS_COVERED_BY_THE_JOB_DURATION, true)) {
                    $scienceSkillIds[] = $requiredSkill['skillId'];
                }
            }
            $scienceSkillIdsByLineId[$lineId] = $scienceSkillIds;
        }

        return $scienceSkillIdsByLineId;
    }

    /**
     * @param array<int, CachedIndustryJob> $cachedJobsById
     *
     * @return list<PlanningLinkedJob>
     */
    private function linkedJobsOf(IndustryProjectBomLine $line, array $cachedJobsById): array
    {
        $linkedJobs = [];
        foreach ($line->getJobMatches() as $match) {
            $cachedJob = $cachedJobsById[$match->getEsiJobId()] ?? null;
            if (null === $cachedJob) {
                continue;
            }
            $linkedJobs[] = new PlanningLinkedJob(
                $match->getStatus(),
                $match->getRuns(),
                $cachedJob->getStartDate(),
                $cachedJob->getEndDate(),
                $match->getCharacterName() ?? $cachedJob->getCharacter()->getName(),
            );
        }

        return $linkedJobs;
    }

    /**
     * @return list<PlanningCharacter>
     */
    private function charactersOf(IndustryProject $project): array
    {
        return array_values(array_map(function (Character $character): PlanningCharacter {
            $skills = $this->skillRepository->findAllSkillsForCharacter($character);
            if ([] === $skills) {
                return new PlanningCharacter($character->getName(), null, null, null);
            }

            $slots = SlotTrackerService::calculateMaxSlots($skills);

            return new PlanningCharacter(
                $character->getName(),
                array_map(static fn (CachedCharacterSkill $skill): int => $skill->getLevel(), $skills),
                $slots['manufacturing'],
                $slots['reaction'],
            );
        }, $this->characterRepository->findByUser($project->getOwner())));
    }
}
