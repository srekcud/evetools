<?php

declare(strict_types=1);

namespace App\Service\Industry\Factory;

use App\Entity\IndustryProject;
use App\Entity\IndustryStructureConfig;
use App\Entity\User;
use App\Enum\StructureAccessStatus;
use App\Industry\Application\SystemCostIndices;
use App\Industry\Domain\ActivityKind;
use App\Industry\Infrastructure\Doctrine\StructureBonusSummary;
use App\Repository\CachedIndustryJobRepository;
use App\Repository\IndustryProjectParticipantRepository;
use App\Repository\IndustryStructureConfigRepository;
use App\Repository\Sde\MapSolarSystemRepository;
use App\Service\Industry\Project\StructureAccess\CharacterStructureAccess;

final readonly class FactoryStations
{
    public const string MANUFACTURING = 'manufacturing';
    public const string REACTION = 'reaction';

    public function __construct(
        private IndustryStructureConfigRepository $structureConfigRepository,
        private SystemCostIndices $costIndices,
        private CharacterStructureAccess $structureAccess,
        private CachedIndustryJobRepository $jobRepository,
        private IndustryProjectParticipantRepository $participantRepository,
        private MapSolarSystemRepository $solarSystemRepository,
        private StructureBonusSummary $structureBonusSummary,
    ) {
    }

    /**
     * @return list<FactoryStation>
     */
    public function of(User $user): array
    {
        $configurations = $this->structureConfigRepository->findByUser($user);
        usort($configurations, static fn (IndustryStructureConfig $left, IndustryStructureConfig $right): int => $left->getName() <=> $right->getName());

        $activeJobsByStation = $this->activeJobsByStation($user);
        $openProjects = $this->openProjectsOf($user);

        return array_map(
            fn (IndustryStructureConfig $configuration): FactoryStation => $this->stationOf($user, $configuration, $activeJobsByStation, $openProjects),
            $configurations,
        );
    }

    /**
     * @param array<int, int>      $activeJobsByStation
     * @param list<IndustryProject> $openProjects
     */
    private function stationOf(User $user, IndustryStructureConfig $configuration, array $activeJobsByStation, array $openProjects): FactoryStation
    {
        $locationId = $configuration->getLocationId();
        $solarSystemId = $configuration->getSolarSystemId();

        return new FactoryStation(
            $configuration->getId()->toRfc4122(),
            $configuration->getName(),
            $configuration->getStructureType(),
            $configuration->getSecurityType(),
            $locationId,
            $solarSystemId,
            null === $solarSystemId ? null : $this->solarSystemRepository->findBySolarSystemId($solarSystemId)?->getSolarSystemName(),
            $this->specialisationsOf($configuration),
            null === $solarSystemId ? null : $this->costIndices->costIndexOf($solarSystemId, ActivityKind::Manufacturing),
            null === $solarSystemId ? null : $this->costIndices->costIndexOf($solarSystemId, ActivityKind::Reaction),
            $this->accessOf($user, $locationId),
            null === $locationId ? null : $activeJobsByStation[$locationId] ?? 0,
            $this->projectsOf($configuration, $openProjects),
        );
    }

    /**
     * @return list<string>
     */
    private function specialisationsOf(IndustryStructureConfig $configuration): array
    {
        $bonuses = $this->structureBonusSummary->of($configuration->getStructureType(), $configuration->getSecurityType(), $configuration->getRigs());
        $specialisations = [];
        if ($bonuses->manufacturingMaterialBonus > 0.0 || (null !== $bonuses->manufacturingTimeBonus && $bonuses->manufacturingTimeBonus > 0.0)) {
            $specialisations[] = self::MANUFACTURING;
        }
        if ($bonuses->reactionMaterialBonus > 0.0 || (null !== $bonuses->reactionTimeBonus && $bonuses->reactionTimeBonus > 0.0)) {
            $specialisations[] = self::REACTION;
        }

        return $specialisations;
    }

    /**
     * @return list<FactoryStationAccess>
     */
    private function accessOf(User $user, ?int $locationId): array
    {
        $access = [];
        foreach ($user->getCharacters() as $character) {
            $access[] = new FactoryStationAccess(
                $character->getEveCharacterId(),
                $character->getName(),
                null === $locationId ? StructureAccessStatus::Unknown : $this->structureAccess->statusOf($character, $locationId),
            );
        }

        return $access;
    }

    /**
     * @return array<int, int> number of active jobs by station id
     */
    private function activeJobsByStation(User $user): array
    {
        $jobsByStation = [];
        foreach ($user->getCharacters() as $character) {
            foreach ($this->jobRepository->findActiveJobsByCharacter($character) as $job) {
                $stationId = $job->getStationId();
                if (null !== $stationId) {
                    $jobsByStation[$stationId] = ($jobsByStation[$stationId] ?? 0) + 1;
                }
            }
        }

        return $jobsByStation;
    }

    /**
     * @return list<IndustryProject>
     */
    private function openProjectsOf(User $user): array
    {
        $projects = [];
        foreach ($this->participantRepository->findAcceptedWithProjectsByUser($user) as $membership) {
            $project = $membership->getProject();
            if (!$project->isCompleted()) {
                $projects[] = $project;
            }
        }

        return $projects;
    }

    /**
     * @param list<IndustryProject> $openProjects
     *
     * @return list<FactoryStationProject>
     */
    private function projectsOf(IndustryStructureConfig $configuration, array $openProjects): array
    {
        $configurationId = $configuration->getId()->toRfc4122();
        $projects = [];
        foreach ($openProjects as $project) {
            foreach ($project->getBomItems() as $line) {
                if ($line->getPlannedStructureId() === $configurationId) {
                    $projects[] = new FactoryStationProject($project->getId()->toRfc4122(), $project->getName());
                    break;
                }
            }
        }
        usort($projects, static fn (FactoryStationProject $left, FactoryStationProject $right): int => (string) $left->name <=> (string) $right->name);

        return $projects;
    }
}
