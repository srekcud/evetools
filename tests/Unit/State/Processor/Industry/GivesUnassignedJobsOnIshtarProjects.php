<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\Industry;

use App\Entity\CachedIndustryJob;
use App\Entity\Character;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectJobMatch;
use App\Entity\UnassignedJob;
use App\Entity\User;
use App\Enum\ParticipantRole;
use App\Enum\ParticipantStatus;
use App\Enum\ProjectStatus;
use App\Repository\IndustryProjectBomLineRepository;
use App\Repository\UnassignedJobRepository;
use App\Service\Industry\Project\UnassignedJobCandidates;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Uid\Uuid;

trait GivesUnassignedJobsOnIshtarProjects
{
    use TracksJobsAndPurchasesOnIshtarBom;

    private const int ISHTAR_JOB_ID = 7_248_201;
    private const int RAM_JOB_ID = 7_248_202;
    private const int ISHTAR_JOB_OF_RIVAL_ID = 7_248_203;
    private const int ISHTAR_BIG_JOB_ID = 7_248_204;
    private const int JITA_STATION_ID = 60_003_760;

    private User $rival;
    private Character $rivalCharacter;
    private IndustryProject $rivalProject;
    private IndustryProject $strangersProject;
    private IndustryProject $completedProject;
    private IndustryProject $draftProject;
    private IndustryProjectBomLine $rivalIshtarLine;
    private IndustryProjectBomLine $strangersIshtarLine;
    private IndustryProjectBomLine $completedIshtarLine;
    private IndustryProjectBomLine $draftIshtarLine;

    private array $unassignedRows = [];

    private function setUpUnassignedJobsWorld(): void
    {
        $this->setUpIshtarProject();
        $this->rival = new User();
        $this->rivalCharacter = $this->characterOf($this->rival, 'Corin Vale');

        $this->rivalProject = $this->projectOf($this->rival, 'Ishtar rival batch', ProjectStatus::InProgress);
        $this->participate($this->rivalProject, $this->rival, ParticipantRole::Owner);
        $this->participate($this->rivalProject, $this->owner, ParticipantRole::Member);
        $this->rivalIshtarLine = $this->jobLine($this->rivalProject, self::ISHTAR_TYPE_ID, 'Ishtar', 'final', 10, 10);

        $this->strangersProject = $this->projectOf($this->rival, 'Ishtar strangers batch', ProjectStatus::InProgress);
        $this->participate($this->strangersProject, $this->rival, ParticipantRole::Owner);
        $this->strangersIshtarLine = $this->jobLine($this->strangersProject, self::ISHTAR_TYPE_ID, 'Ishtar', 'final', 8, 8);

        $this->completedProject = $this->projectOf($this->owner, 'Ishtar finished batch', ProjectStatus::Completed);
        $this->participate($this->completedProject, $this->owner, ParticipantRole::Owner);
        $this->completedIshtarLine = $this->jobLine($this->completedProject, self::ISHTAR_TYPE_ID, 'Ishtar', 'final', 6, 6);

        $this->draftProject = $this->projectOf($this->owner, 'Ishtar draft batch', ProjectStatus::Draft);
        $this->participate($this->draftProject, $this->owner, ParticipantRole::Owner);
        $this->draftIshtarLine = $this->jobLine($this->draftProject, self::ISHTAR_TYPE_ID, 'Ishtar', 'final', 7, 7);

        $this->ishtarProject->setStatus(ProjectStatus::InProgress);
    }

    private function projectOf(User $owner, string $name, ProjectStatus $status): IndustryProject
    {
        return (new IndustryProject())->setOwner($owner)->setName($name)->setStatus($status);
    }

    private function allProjects(): array
    {
        return [$this->ishtarProject, $this->hecateProject, $this->rivalProject, $this->strangersProject, $this->completedProject, $this->draftProject];
    }

    private function givenUnassignedJob(int $esiJobId, Character $installer, int $blueprintTypeId, int $productTypeId, int $runs, string $startDate, array $candidates, string $status = 'active', ?int $stationId = null, int $activityId = 1): UnassignedJob
    {
        $job = (new CachedIndustryJob())
            ->setJobId($esiJobId)
            ->setCharacter($installer)
            ->setActivityId($activityId)
            ->setBlueprintTypeId($blueprintTypeId)
            ->setProductTypeId($productTypeId)
            ->setRuns($runs)
            ->setStatus($status)
            ->setCost(1_250_000.5)
            ->setStationId($stationId)
            ->setStartDate(new \DateTimeImmutable($startDate))
            ->setEndDate((new \DateTimeImmutable($startDate))->modify('+2 days'));
        $row = (new UnassignedJob())->setJob($job)->setCandidates($candidates);
        $this->unassignedRows[$esiJobId] = $row;

        return $row;
    }

    private function candidateOf(IndustryProject $project, IndustryProjectBomLine $line, int $score = 0, array $signals = [], bool $oversized = false): array
    {
        return [
            'projectId' => $project->getId()->toRfc4122(),
            'lineId' => $line->getId()->toRfc4122(),
            'score' => $score,
            'signals' => $signals,
            'oversized' => $oversized,
        ];
    }

    private function matchRunsOn(IndustryProjectBomLine $line, int $esiJobId, int $runs): void
    {
        $match = (new IndustryProjectJobMatch())->setEsiJobId($esiJobId)->setRuns($runs)->setStatus('active');
        (new \ReflectionProperty(IndustryProjectJobMatch::class, 'id'))->setValue($match, Uuid::v4());
        $line->addJobMatch($match);
    }

    private function unassignedJobRepository(): UnassignedJobRepository
    {
        $repository = $this->createStub(UnassignedJobRepository::class);
        $repository->method('findOfUser')->willReturnCallback(
            fn (User $user): array => array_values(array_filter(
                $this->unassignedRows,
                static fn (UnassignedJob $row): bool => $row->getJob()->getCharacter()->getUser() === $user && $row->getDismissedAt() === null,
            )),
        );
        $repository->method('findOneOfUser')->willReturnCallback(
            function (User $user, int $esiJobId): ?UnassignedJob {
                $row = $this->unassignedRows[$esiJobId] ?? null;

                return $row !== null && $row->getJob()->getCharacter()->getUser() === $user ? $row : null;
            },
        );
        $repository->method('findByEsiJobIds')->willReturnCallback(
            fn (array $esiJobIds): array => array_intersect_key($this->unassignedRows, array_flip($esiJobIds)),
        );

        return $repository;
    }

    private function allLinesRepository(): IndustryProjectBomLineRepository
    {
        $repository = $this->createStub(IndustryProjectBomLineRepository::class);
        $repository->method('find')->willReturnCallback(function (Uuid|string $id): ?IndustryProjectBomLine {
            $wanted = $id instanceof Uuid ? $id->toRfc4122() : strtolower($id);
            foreach ($this->allProjects() as $project) {
                foreach ($project->getBomItems() as $line) {
                    if ($line->getId()->toRfc4122() === $wanted) {
                        return $line;
                    }
                }
            }

            return null;
        });

        return $repository;
    }

    private function unassignedJobCandidates(): UnassignedJobCandidates
    {
        return new UnassignedJobCandidates($this->allLinesRepository(), $this->accessChecker());
    }

    private function statusOf(callable $operation): int|string
    {
        try {
            $operation();
        } catch (HttpExceptionInterface $exception) {
            return $exception->getStatusCode();
        }

        return self::GRANTED;
    }
}
