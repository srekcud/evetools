<?php

declare(strict_types=1);

namespace App\Service\Industry\Project\StructureAccess;

use App\Entity\Character;
use App\Entity\CharacterStructureAccess;
use App\Entity\IndustryProject;
use App\Enum\StructureAccessSource;
use App\Enum\StructureAccessStatus;
use App\Repository\CachedIndustryJobRepository;
use App\Repository\CharacterStructureAccessRepository;
use App\Service\ESI\EsiErrorBudget;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

final readonly class StructureAccessRefresher
{
    public const int MAXIMUM_PROBES = 40;
    public const int DENIED_RECHECK_HOURS = 24;
    public const int GRANTED_RECHECK_DAYS = 7;

    private const string BUDGET_LEVEL_OK = 'ok';

    public function __construct(
        private PlanStations $planStations,
        private CharacterStructureAccessRepository $accessRepository,
        private CachedIndustryJobRepository $jobRepository,
        private StructureAccessProbe $probe,
        private EsiErrorBudget $errorBudget,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    public function refreshProject(IndustryProject $project): StructureAccessRefreshReport
    {
        $now = $this->clock->now();
        $probed = 0;
        $skipped = 0;
        $stoppedForBudget = false;
        $capped = false;

        foreach ($this->pairsOf($project) as [$character, $structureId]) {
            $row = $this->accessRepository->findOneByCharacterAndStructure($character, $structureId);

            $latestJobEnd = $this->jobRepository->latestEndDateAtStructure($character, $structureId);
            if (null !== $latestJobEnd && (null === $row || StructureAccessStatus::Granted === $row->getStatus() || $latestJobEnd >= $row->getCheckedAt())) {
                $this->record($character, $structureId, $row, StructureAccessStatus::Granted, StructureAccessSource::Job, $now);
                ++$skipped;
                continue;
            }

            if (null !== $row && $this->isFresh($row, $now)) {
                ++$skipped;
                continue;
            }

            if (self::BUDGET_LEVEL_OK !== $this->errorBudget->snapshot()['level']) {
                $stoppedForBudget = true;
                break;
            }

            if ($probed >= self::MAXIMUM_PROBES) {
                $capped = true;
                break;
            }

            ++$probed;
            $status = $this->probe->probe($character, $structureId);
            if (StructureAccessStatus::Unknown !== $status) {
                $this->record($character, $structureId, $row, $status, StructureAccessSource::Esi, $now);
            }
        }

        $this->entityManager->flush();

        return new StructureAccessRefreshReport($probed, $skipped, $stoppedForBudget, $capped);
    }

    /**
     * @return \Generator<array{0: Character, 1: int}>
     */
    private function pairsOf(IndustryProject $project): \Generator
    {
        $structureIds = [];
        foreach ($this->planStations->of($project) as $station) {
            if (null !== $station->locationId && StructureIds::isStructure($station->locationId)) {
                $structureIds[] = $station->locationId;
            }
        }

        foreach ($project->acceptedUsers() as $member) {
            foreach ($member->getCharacters() as $character) {
                foreach ($structureIds as $structureId) {
                    yield [$character, $structureId];
                }
            }
        }
    }

    private function isFresh(CharacterStructureAccess $row, \DateTimeImmutable $now): bool
    {
        if (StructureAccessStatus::Denied === $row->getStatus()) {
            return $row->getCheckedAt() > $now->modify(sprintf('-%d hours', self::DENIED_RECHECK_HOURS));
        }

        return StructureAccessSource::Esi === $row->getSource()
            && $row->getCheckedAt() > $now->modify(sprintf('-%d days', self::GRANTED_RECHECK_DAYS));
    }

    private function record(
        Character $character,
        int $structureId,
        ?CharacterStructureAccess $row,
        StructureAccessStatus $status,
        StructureAccessSource $source,
        \DateTimeImmutable $checkedAt,
    ): void {
        if (null === $row) {
            $this->entityManager->persist(new CharacterStructureAccess($character, $structureId, $status, $source, $checkedAt));

            return;
        }

        $row->record($status, $source, $checkedAt);
    }
}
