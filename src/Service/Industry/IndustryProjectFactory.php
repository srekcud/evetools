<?php

declare(strict_types=1);

namespace App\Service\Industry;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectStep;
use App\Entity\User;
use App\Enum\IndustryActivityType;
use App\Repository\CachedCharacterSkillRepository;
use App\Repository\Sde\IndustryActivityMaterialRepository;
use App\Repository\Sde\IndustryActivityRepository;
use App\Repository\Sde\InvTypeRepository;
use App\Service\Mercure\MercurePublisherService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Creates industry projects and (re)generates their production steps.
 *
 * Responsible for building the step list from the production tree,
 * consolidating quantities, computing time data, and splitting long jobs.
 */
class IndustryProjectFactory
{
    private const SECONDS_PER_DAY = 86400;

    public function __construct(
        private readonly IndustryTreeService $treeService,
        private readonly IndustryBlacklistService $blacklistService,
        private readonly IndustryBonusService $bonusService,
        private readonly IndustryCalculationService $calculationService,
        private readonly CachedCharacterSkillRepository $skillRepository,
        private readonly InvTypeRepository $invTypeRepository,
        private readonly IndustryActivityMaterialRepository $materialRepository,
        private readonly IndustryActivityRepository $activityRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly MercurePublisherService $mercurePublisher,
    ) {
    }

    public function createProject(User $user, int $productTypeId, int $runs, int $meLevel, float $maxJobDurationDays = 2.0, int $teLevel = 0, ?string $name = null): IndustryProject
    {
        $type = $this->invTypeRepository->find($productTypeId);
        if ($type === null) {
            throw new \InvalidArgumentException("Unknown type ID {$productTypeId}");
        }

        $excludedTypeIds = $this->blacklistService->resolveBlacklistedTypeIds($user);
        $tree = $this->treeService->buildProductionTree($productTypeId, $runs, $meLevel, $excludedTypeIds, $user);

        $project = new IndustryProject();
        $project->setUser($user);
        $project->setProductTypeId($productTypeId);
        $project->setName($name !== '' ? $name : null);
        $project->setRuns($runs);
        $project->setMeLevel($meLevel);
        $project->setTeLevel($teLevel);
        $project->setMaxJobDurationDays($maxJobDurationDays);

        $rawSteps = [];
        $this->collectStepsFromTree($rawSteps, $tree, $meLevel, $teLevel);
        $this->recalculateReactionQuantities($rawSteps, $user);
        $this->addTimeDataToSteps($rawSteps, $user, $project->getTeLevel());
        $rawSteps = $this->splitLongJobs($rawSteps, $project->getMaxJobDurationDays());

        $this->sortAndCreateSteps($project, $rawSteps);

        $this->entityManager->persist($project);
        $this->entityManager->flush();

        return $project;
    }

    public function regenerateSteps(IndustryProject $project): void
    {
        $user = $project->getUser();
        $userId = $user->getId()?->toRfc4122();

        if ($userId !== null) {
            $this->mercurePublisher->syncStarted($userId, 'industry-project', 'Regenerating steps...');
        }

        try {
            foreach ($project->getSteps()->toArray() as $step) {
                $project->getSteps()->removeElement($step);
                $this->entityManager->remove($step);
            }

            $excludedTypeIds = $this->blacklistService->resolveBlacklistedTypeIds($user);
            $tree = $this->treeService->buildProductionTree(
                $project->getProductTypeId(),
                $project->getRuns(),
                $project->getMeLevel(),
                $excludedTypeIds,
                $user,
            );

            $rawSteps = [];
            $this->collectStepsFromTree($rawSteps, $tree, $project->getMeLevel(), $project->getTeLevel());
            $this->recalculateReactionQuantities($rawSteps, $user);
            $this->addTimeDataToSteps($rawSteps, $user, $project->getTeLevel());
            $rawSteps = $this->splitLongJobs($rawSteps, $project->getMaxJobDurationDays());

            $this->sortAndCreateSteps($project, $rawSteps);

            $this->entityManager->flush();

            if ($userId !== null) {
                $this->mercurePublisher->syncCompleted($userId, 'industry-project', 'Steps regenerated', [
                    'projectId' => $project->getId()?->toRfc4122() ?? '',
                    'stepsCount' => count($rawSteps),
                ]);
            }
        } catch (\Throwable $e) {
            if ($userId !== null) {
                $this->mercurePublisher->syncError($userId, 'industry-project', $e->getMessage());
            }
            throw $e;
        }
    }

    /**
     * Sort raw steps and create entities on the project.
     *
     * @param list<array<string, mixed>> $rawSteps
     */
    private function sortAndCreateSteps(IndustryProject $project, array &$rawSteps): void
    {
        $activityOrder = ['reaction' => 0, 'copy' => 1, 'manufacturing' => 2];
        usort($rawSteps, function (array $a, array $b) use ($activityOrder) {
            if ($a['depth'] !== $b['depth']) {
                return $b['depth'] <=> $a['depth'];
            }
            $aOrder = $activityOrder[$a['activityType']] ?? 99;
            $bOrder = $activityOrder[$b['activityType']] ?? 99;
            if ($aOrder !== $bOrder) {
                return $aOrder <=> $bOrder;
            }
            $aGroup = $a['splitGroupId'] ?? '';
            $bGroup = $b['splitGroupId'] ?? '';
            if ($aGroup !== $bGroup) {
                return strcmp($aGroup, $bGroup);
            }
            if (($a['splitIndex'] ?? 0) !== ($b['splitIndex'] ?? 0)) {
                return ($a['splitIndex'] ?? 0) <=> ($b['splitIndex'] ?? 0);
            }
            return strcasecmp($a['productTypeName'], $b['productTypeName']);
        });

        foreach ($rawSteps as $index => $data) {
            $step = new IndustryProjectStep();
            $step->setBlueprintTypeId($data['blueprintTypeId']);
            $step->setProductTypeId($data['productTypeId']);
            $step->setQuantity($data['quantity']);
            $step->setRuns($data['runs']);
            $step->setDepth($data['depth']);
            $step->setActivityType($data['activityType']);
            $step->setSortOrder($index);
            $step->setSplitGroupId($data['splitGroupId'] ?? null);
            $step->setSplitIndex($data['splitIndex'] ?? 0);
            $step->setTotalGroupRuns($data['totalGroupRuns'] ?? null);

            $step->setMeLevel($data['meLevel'] ?? ($data['depth'] === 0 ? $project->getMeLevel() : 10));
            $step->setTeLevel($data['teLevel'] ?? ($data['depth'] === 0 ? $project->getTeLevel() : 20));

            $project->addStep($step);
        }
    }

    /**
     * Flatten the tree into an array of step data.
     * Steps are consolidated by (blueprintTypeId, activityType).
     *
     * @param array<string, array<string, mixed>> $steps
     * @param array<string, mixed> $node
     */
    private function collectStepsFromTree(array &$steps, array $node, int $rootMeLevel = 0, int $rootTeLevel = 0): void
    {
        if (!empty($node['hasCopy'])) {
            $copyKey = $node['blueprintTypeId'] . '_copy';
            if (isset($steps[$copyKey])) {
                $steps[$copyKey]['quantity'] += $node['runs'];
                $steps[$copyKey]['runs'] = $steps[$copyKey]['quantity'];
            } else {
                $steps[$copyKey] = [
                    'blueprintTypeId' => $node['blueprintTypeId'],
                    'productTypeId' => $node['productTypeId'],
                    'productTypeName' => $node['productTypeName'] . ' (BPC)',
                    'quantity' => $node['runs'],
                    'runs' => $node['runs'],
                    'outputPerRun' => 1,
                    'depth' => $node['depth'],
                    'activityType' => 'copy',
                    'meLevel' => 0,
                    'teLevel' => 0,
                ];
            }
        }

        $activityType = $node['activityType'];
        $key = $node['blueprintTypeId'] . '_' . $activityType;
        $outputPerRun = $node['outputPerRun'] ?? 1;

        // Determine ME/TE for this step based on depth and activity type
        $depth = $node['depth'];
        if ($activityType === 'reaction') {
            // Reactions have no ME/TE research
            $stepMeLevel = 0;
            $stepTeLevel = 0;
        } elseif ($depth === 0) {
            // Root blueprint: use user-specified values
            $stepMeLevel = $rootMeLevel;
            $stepTeLevel = $rootTeLevel;
        } else {
            // Intermediate components: standard EVE defaults (ME 10 / TE 20)
            $stepMeLevel = 10;
            $stepTeLevel = 20;
        }

        if (isset($steps[$key])) {
            $steps[$key]['quantity'] += $node['quantity'];
            $steps[$key]['runs'] = (int) ceil($steps[$key]['quantity'] / $steps[$key]['outputPerRun']);
        } else {
            $steps[$key] = [
                'blueprintTypeId' => $node['blueprintTypeId'],
                'productTypeId' => $node['productTypeId'],
                'productTypeName' => $node['productTypeName'],
                'quantity' => $node['quantity'],
                'runs' => $node['runs'],
                'outputPerRun' => $outputPerRun,
                'depth' => $node['depth'],
                'activityType' => $activityType,
                'meLevel' => $stepMeLevel,
                'teLevel' => $stepTeLevel,
            ];
        }

        foreach ($node['materials'] as $material) {
            if (($material['isBuildable'] ?? false) && isset($material['blueprint'])) {
                $this->collectStepsFromTree($steps, $material['blueprint'], $rootMeLevel, $rootTeLevel);
            }
        }
    }

    /**
     * Recalculate reaction step quantities based on consolidated consumer needs.
     *
     * @param array<string, array<string, mixed>> $steps
     */
    private function recalculateReactionQuantities(array &$steps, User $user): void
    {
        $reactionSteps = [];

        foreach ($steps as $key => &$step) {
            if ($step['activityType'] === 'reaction') {
                $reactionSteps[$key] = &$step;
            }
        }
        unset($step);

        if (empty($reactionSteps)) {
            return;
        }

        uasort($reactionSteps, fn ($a, $b) => $a['depth'] <=> $b['depth']);

        // Preload all materials for all consumer steps (non-copy) in one batch query
        $consumerTypeIds = [];
        $consumerActivityIds = [];
        foreach ($steps as $step) {
            if ($step['activityType'] === 'copy') {
                continue;
            }
            $activityId = match ($step['activityType']) {
                'reaction' => IndustryActivityType::Reaction->value,
                default => IndustryActivityType::Manufacturing->value,
            };
            $consumerTypeIds[] = $step['blueprintTypeId'];
            $consumerActivityIds[] = $activityId;
        }
        $materialsByKey = $this->materialRepository->findMaterialEntitiesForBlueprints($consumerTypeIds, $consumerActivityIds);

        foreach ($reactionSteps as $reactionKey => &$reaction) {
            $productTypeId = $reaction['productTypeId'];
            $totalNeeded = 0;

            foreach ($steps as $consumerKey => &$consumer) {
                if ($consumerKey === $reactionKey) {
                    continue;
                }
                if ($consumer['activityType'] === 'copy') {
                    continue;
                }

                $activityId = match ($consumer['activityType']) {
                    'reaction' => IndustryActivityType::Reaction->value,
                    default => IndustryActivityType::Manufacturing->value,
                };

                $materialKey = $consumer['blueprintTypeId'] . '-' . $activityId;
                $materials = $materialsByKey[$materialKey] ?? [];

                foreach ($materials as $material) {
                    if ($material->getMaterialTypeId() === $productTypeId) {
                        $baseQty = $material->getQuantity();
                        $runs = $consumer['runs'];

                        $meMultiplier = 1.0;
                        if ($activityId === IndustryActivityType::Manufacturing->value) {
                            $meMultiplier = 1 - 10 / 100;
                        }

                        $structureBaseBonus = 0.0;
                        $rigBonus = 0.0;
                        if ($activityId === IndustryActivityType::Reaction->value) {
                            $consumerCategory = $this->bonusService->getCategoryForProduct($consumer['productTypeId'], true);
                            if ($consumerCategory !== null) {
                                $bonusData = $this->bonusService->findBestStructureForCategory($user, $consumerCategory, true);
                                $structureBaseBonus = $bonusData['bonus']['base'];
                                $rigBonus = $bonusData['bonus']['rig'];
                            }
                        } else {
                            $consumerCategory = $this->bonusService->getCategoryForProduct($consumer['productTypeId'], false);
                            if ($consumerCategory !== null) {
                                $bonusData = $this->bonusService->findBestStructureForCategory($user, $consumerCategory, false);
                                $structureBaseBonus = $bonusData['bonus']['base'];
                                $rigBonus = $bonusData['bonus']['rig'];
                            }
                        }
                        $structureMultiplier = $structureBaseBonus > 0 ? (1 - $structureBaseBonus / 100) : 1.0;
                        $rigMultiplier = $rigBonus > 0 ? (1 - $rigBonus / 100) : 1.0;

                        $adjustedQty = max(
                            $runs,
                            (int) ceil(round($baseQty * $runs * $meMultiplier * $structureMultiplier * $rigMultiplier, 2))
                        );

                        $totalNeeded += $adjustedQty;
                        break;
                    }
                }
            }
            unset($consumer);

            if ($totalNeeded > 0) {
                $outputPerRun = $reaction['outputPerRun'] ?? 200;
                $steps[$reactionKey]['quantity'] = $totalNeeded;
                $steps[$reactionKey]['runs'] = (int) ceil($totalNeeded / $outputPerRun);
            }
        }
        unset($reaction);
    }

    /**
     * @param array<string, array<string, mixed>> $steps
     */
    private function addTimeDataToSteps(array &$steps, User $user, int $projectTeLevel = 20): void
    {
        // Preload all skills for all characters (one query per character)
        $characterSkillLevels = $this->loadAllCharacterSkills($user);

        // Preload all activities in one batch query
        $allTypeIds = [];
        $allActivityIds = [];
        foreach ($steps as $step) {
            $activityId = match ($step['activityType']) {
                'reaction' => IndustryActivityType::Reaction->value,
                'copy' => IndustryActivityType::Copying->value,
                default => IndustryActivityType::Manufacturing->value,
            };
            $allTypeIds[] = $step['blueprintTypeId'];
            $allActivityIds[] = $activityId;
        }
        $activitiesByKey = $this->activityRepository->findByTypeIdsAndActivityIds($allTypeIds, $allActivityIds);

        foreach ($steps as &$step) {
            $activityId = match ($step['activityType']) {
                'reaction' => IndustryActivityType::Reaction->value,
                'copy' => IndustryActivityType::Copying->value,
                default => IndustryActivityType::Manufacturing->value,
            };

            $activityKey = $step['blueprintTypeId'] . '-' . $activityId;
            $activity = $activitiesByKey[$activityKey] ?? null;

            $baseTimePerRun = $activity?->getTime();
            $step['baseTimePerRun'] = $baseTimePerRun;

            if ($baseTimePerRun === null) {
                $step['timePerRun'] = null;
                continue;
            }

            $isReaction = $step['activityType'] === 'reaction';
            $timeBonusData = $this->bonusService->findBestStructureForProductTimeBonus(
                $user,
                $step['productTypeId'],
                $isReaction
            );
            $structureTimeBonus = $timeBonusData['bonus'];

            if ($isReaction) {
                $teLevel = 0;
            } else {
                $teLevel = ($step['depth'] ?? 0) === 0 ? $projectTeLevel : 20;
            }

            $adjustedTimePerRun = $this->bonusService->calculateAdjustedTimePerRun(
                $baseTimePerRun,
                $teLevel,
                $structureTimeBonus
            );

            // Find the best character's skill multiplier for this specific blueprint
            $skillMultiplier = $this->findBestSkillMultiplierForBlueprint(
                $characterSkillLevels,
                $step['blueprintTypeId'],
                $step['activityType'],
            );
            $adjustedTimePerRun = (int) ceil($adjustedTimePerRun * $skillMultiplier);

            $step['timePerRun'] = $adjustedTimePerRun;
        }
        unset($step);
    }

    /**
     * Load all cached skills for all user's characters.
     *
     * @return array<string, array<int, int>> characterName => [skillId => level]
     */
    private function loadAllCharacterSkills(User $user): array
    {
        $result = [];
        foreach ($user->getCharacters() as $character) {
            $charSkills = $this->skillRepository->findAllSkillsForCharacter($character);
            $levels = [];
            foreach ($charSkills as $skill) {
                $levels[$skill->getSkillId()] = $skill->getLevel();
            }
            $result[$character->getName()] = $levels;
        }
        return $result;
    }

    /**
     * Find the best (lowest) skill time multiplier for a specific blueprint
     * across all characters, including blueprint-specific science skills.
     *
     * @param array<string, array<int, int>> $characterSkillLevels
     */
    private function findBestSkillMultiplierForBlueprint(
        array $characterSkillLevels,
        int $blueprintTypeId,
        string $activityType,
    ): float {
        $scienceSkillIds = $this->calculationService->getBlueprintScienceSkillIds($blueprintTypeId, $activityType);

        $bestMultiplier = 1.0;

        foreach ($characterSkillLevels as $skillLevels) {
            $multiplier = $this->calculationService->calculateSkillTimeMultiplier($skillLevels, $activityType, $scienceSkillIds);

            if ($multiplier < $bestMultiplier) {
                $bestMultiplier = $multiplier;
            }
        }

        return $bestMultiplier;
    }

    /**
     * @param array<string, array<string, mixed>> $steps
     * @return list<array<string, mixed>>
     */
    private function splitLongJobs(array $steps, float $maxDurationDays): array
    {
        $result = [];
        $maxDurationSeconds = $maxDurationDays * self::SECONDS_PER_DAY;

        foreach ($steps as $step) {
            $timePerRun = $step['timePerRun'] ?? null;

            if ($timePerRun === null || $step['activityType'] === 'copy') {
                $result[] = $step;
                continue;
            }

            $totalRuns = $step['runs'];
            $totalDuration = $timePerRun * $totalRuns;

            if ($totalDuration <= $maxDurationSeconds) {
                $result[] = $step;
                continue;
            }

            $maxRunsPerJob = max(1, (int) floor($maxDurationSeconds / $timePerRun));
            $numJobs = (int) ceil($totalRuns / $maxRunsPerJob);
            $baseRunsPerJob = (int) floor($totalRuns / $numJobs);
            $remainder = $totalRuns - ($baseRunsPerJob * $numJobs);

            $splitGroupId = Uuid::v4()->toRfc4122();
            $outputPerRun = $step['outputPerRun'] ?? 1;

            for ($splitIndex = 0; $splitIndex < $numJobs; $splitIndex++) {
                $runsForThisJob = $baseRunsPerJob + ($splitIndex < $remainder ? 1 : 0);
                $quantityForThisJob = $runsForThisJob * $outputPerRun;

                $splitStep = $step;
                $splitStep['runs'] = $runsForThisJob;
                $splitStep['quantity'] = $quantityForThisJob;
                $splitStep['splitGroupId'] = $splitGroupId;
                $splitStep['splitIndex'] = $splitIndex;
                $splitStep['totalGroupRuns'] = $totalRuns;

                $result[] = $splitStep;
            }
        }

        return $result;
    }
}
