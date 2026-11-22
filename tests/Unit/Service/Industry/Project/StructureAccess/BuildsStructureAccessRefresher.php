<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project\StructureAccess;

use App\Entity\Character;
use App\Entity\CharacterStructureAccess;
use App\Entity\IndustryProject;
use App\Entity\User;
use App\Enum\ParticipantRole;
use App\Enum\ParticipantStatus;
use App\Enum\ProjectStatus;
use App\Service\ESI\EsiErrorBudget;
use App\Service\Industry\Project\StructureAccess\PlanStations;
use App\Service\Industry\Project\StructureAccess\StructureAccessRefresher;
use App\Tests\Unit\Service\Industry\Project\Action\BuildsActionPlaces;
use App\Tests\Unit\State\Provider\Industry\Project\BuildsWorkshopProjects;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\Response\MockResponse;

trait BuildsStructureAccessRefresher
{
    use BuildsActionPlaces;
    use BuildsStructureAccess;
    use BuildsWorkshopProjects;

    private const int COMPONENT_TYPE_ID = 11_540;

    private array $ownerConfigs = [];
    private array $initialRows = [];
    private array $persistedRows = [];

    private function projectPlannedAt(User $owner, array $locationIds): IndustryProject
    {
        $project = $this->workshopProject('Ishtar batch', ProjectStatus::InProgress, $owner);
        $this->ownerConfigs = [];
        foreach ($locationIds as $index => $locationId) {
            $configuration = $this->azbelConfig($locationId);
            $this->ownerConfigs[] = $configuration;
            $this->jobOf($project, self::COMPONENT_TYPE_ID + $index, 'Component '.$index, 'component', 3, 3, 0)->setPlannedStructureId($configuration->getId()->toRfc4122());
        }

        return $project;
    }

    private function holdRow(CharacterStructureAccess $row): void
    {
        $this->initialRows[$this->proofKeyOf($row->getCharacter(), $row->getStructureId())] = $row;
    }

    private function rowAfter(Character $character, int $structureId): ?CharacterStructureAccess
    {
        $key = $this->proofKeyOf($character, $structureId);
        $persisted = null;
        foreach ($this->persistedRows as $row) {
            if ($this->proofKeyOf($row->getCharacter(), $row->getStructureId()) === $key) {
                $persisted = $row;
            }
        }

        return $persisted ?? $this->initialRows[$key] ?? null;
    }

    /**
     * @param list<User> $acceptedMembers
     * @param array<string, \DateTimeImmutable> $jobEndDates
     */
    private function refresherFor(IndustryProject $project, array $acceptedMembers, array $jobEndDates = [], string $budgetLevel = 'ok', int $answerCode = 200): StructureAccessRefresher
    {
        foreach ($acceptedMembers as $member) {
            if ($member !== $project->getOwner()) {
                $this->membershipOf($project, $member, ParticipantRole::Member, ParticipantStatus::Accepted);
            }
        }

        $errorBudget = $this->createStub(EsiErrorBudget::class);
        $errorBudget->method('snapshot')->willReturn(['level' => $budgetLevel]);

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('persist')->willReturnCallback(function (object $entity): void {
            if ($entity instanceof CharacterStructureAccess) {
                $this->persistedRows[] = $entity;
            }
        });

        return new StructureAccessRefresher(
            new PlanStations($this->placeResolver($this->ownerConfigs)),
            $this->accessRepositoryHolding(...array_values($this->initialRows)),
            $this->jobRepositoryHolding($jobEndDates),
            $this->probeAnswering(static fn (): MockResponse => new MockResponse(200 === $answerCode ? '{"name":"Keepstar"}' : '{"error":"refused"}', ['http_code' => $answerCode])),
            $errorBudget,
            $entityManager,
            new MockClock(self::PROBE_NOW),
        );
    }
}
