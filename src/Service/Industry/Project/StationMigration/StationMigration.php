<?php

declare(strict_types=1);

namespace App\Service\Industry\Project\StationMigration;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectContainer;
use App\Repository\IndustryProjectRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class StationMigration
{
    public function __construct(
        private ProjectStationMigration $projectMigration,
        private IndustryProjectRepository $projectRepository,
        private StationMigrationLog $log,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function run(bool $apply, ?string $projectId, ?string $logPath): StationMigrationReport
    {
        if ($apply && null === $logPath) {
            throw new \LogicException('Applying the station migration needs a change log to revert it.');
        }
        if (null !== $projectId && !Uuid::isValid($projectId)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a project id.', $projectId));
        }

        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();
        try {
            $projectReports = [];
            foreach (null === $projectId ? $this->projectRepository->findAllIdsOldestFirst() : [$projectId] as $id) {
                $project = $this->entityManager->find(IndustryProject::class, $id)
                    ?? throw new \InvalidArgumentException(\sprintf('Project "%s" does not exist.', $id));
                $projectReports[] = $this->projectMigration->migrate($project);
                $this->entityManager->flush();
                $this->entityManager->clear();
            }

            $report = new StationMigrationReport($projectReports);
            if ($apply) {
                $this->log->write($logPath, $report->changes(), new \DateTimeImmutable());
                $connection->commit();
            } else {
                $connection->rollBack();
            }
        } catch (\Throwable $e) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            $this->entityManager->clear();

            throw $e;
        }

        return $report;
    }

    public function revert(string $logPath, bool $apply): StationRevertReport
    {
        $changes = $this->log->read($logPath);

        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();
        try {
            $reverted = [];
            $changedSince = [];
            foreach ($changes as $change) {
                if ($this->undo($change)) {
                    $reverted[] = $change;
                } else {
                    $changedSince[] = $change;
                }
            }

            $this->entityManager->flush();
            $this->entityManager->clear();
            if ($apply) {
                $connection->commit();
            } else {
                $connection->rollBack();
            }
        } catch (\Throwable $e) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            $this->entityManager->clear();

            throw $e;
        }

        return new StationRevertReport($reverted, $changedSince);
    }

    private function undo(StationMigrationChange $change): bool
    {
        return match ($change->kind) {
            StationMigrationChangeKind::WorkStation => $this->undoWorkStation($change),
            StationMigrationChangeKind::ContainerLocation => $this->undoContainerLocation($change),
        };
    }

    private function undoWorkStation(StationMigrationChange $change): bool
    {
        $project = Uuid::isValid($change->projectId) ? $this->entityManager->find(IndustryProject::class, $change->projectId) : null;
        if (null === $project) {
            return false;
        }

        $workStations = $project->getWorkStations();
        $stored = $workStations[$change->subject] ?? null;
        if (null === $stored || $stored['structureId'] !== $change->value || null !== $stored['recommendedStructureId']) {
            return false;
        }

        unset($workStations[$change->subject]);
        $project->setWorkStations($workStations);

        return true;
    }

    private function undoContainerLocation(StationMigrationChange $change): bool
    {
        $container = Uuid::isValid($change->subject) ? $this->entityManager->find(IndustryProjectContainer::class, $change->subject) : null;
        if (null === $container || $container->getProject()->getId()->toRfc4122() !== $change->projectId || (string) $container->getLocationId() !== $change->value) {
            return false;
        }

        $container->clearLocation();
        $this->removeWhenAnotherUnlocatedContainerHasTheSameName($container);

        return true;
    }

    private function removeWhenAnotherUnlocatedContainerHasTheSameName(IndustryProjectContainer $container): void
    {
        $projectContainers = $container->getProject()->getContainers();
        $nameKey = IndustryProjectContainer::keyOf($container->getName());
        $hasTwin = array_any(
            $projectContainers->toArray(),
            static fn (IndustryProjectContainer $other): bool => $other !== $container && null === $other->getLocationId() && IndustryProjectContainer::keyOf($other->getName()) === $nameKey,
        );
        if ($hasTwin) {
            $projectContainers->removeElement($container);
        }
    }
}
