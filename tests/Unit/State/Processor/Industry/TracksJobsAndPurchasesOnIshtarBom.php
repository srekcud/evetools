<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\Industry;

use App\Entity\Character;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectParticipant;
use App\Entity\User;
use App\Enum\ParticipantRole;
use App\Enum\ParticipantStatus;
use App\Repository\IndustryProjectBomLineRepository;
use App\Repository\IndustryProjectParticipantRepository;
use App\Repository\IndustryProjectRepository;
use App\State\Provider\Industry\Project\ProjectAccessChecker;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Uid\Uuid;

trait TracksJobsAndPurchasesOnIshtarBom
{
    private const int ISHTAR_TYPE_ID = 12005;
    private const int ISHTAR_BLUEPRINT_TYPE_ID = 12006;
    private const int ISHTAR_LINE_RUNS = 5;
    private const int RAM_TYPE_ID = 11478;
    private const int RAM_BLUEPRINT_TYPE_ID = 11479;
    private const int RAM_LINE_RUNS = 4;
    private const int RAM_LINE_QUANTITY = 400;
    private const int TRITANIUM_TYPE_ID = 34;
    private const int TRITANIUM_LINE_QUANTITY = 12_000;
    private const string GRANTED = 'granted';
    private const string OWNER_ONLY = '403 Only the project owner can perform this action';

    private User $owner;
    private Character $ownerMainCharacter;
    private Character $ownerAltCharacter;
    private User $admin;
    private Character $adminCharacter;
    private IndustryProject $ishtarProject;
    private IndustryProject $hecateProject;
    private IndustryProjectBomLine $ishtarLine;
    private IndustryProjectBomLine $ramLine;
    private IndustryProjectBomLine $tritaniumLine;
    private IndustryProjectBomLine $hecateTritaniumLine;

    private array $participants = [];

    private function setUpIshtarProject(): void
    {
        $this->owner = new User();
        $this->ownerMainCharacter = $this->characterOf($this->owner, 'Aria Solberg');
        $this->ownerAltCharacter = $this->characterOf($this->owner, 'Aria Alt');
        $this->admin = new User();
        $this->adminCharacter = $this->characterOf($this->admin, 'Brann Holt');

        $this->ishtarProject = $this->projectOfTheOwner('Ishtar batch');
        $this->participate($this->ishtarProject, $this->owner, ParticipantRole::Owner);
        $this->participate($this->ishtarProject, $this->admin, ParticipantRole::Admin);
        $this->ishtarLine = $this->jobLine($this->ishtarProject, self::ISHTAR_TYPE_ID, 'Ishtar', 'final', self::ISHTAR_LINE_RUNS, self::ISHTAR_LINE_RUNS);
        $this->ramLine = $this->jobLine($this->ishtarProject, self::RAM_TYPE_ID, 'R.A.M.- Starship Tech', 'component', self::RAM_LINE_RUNS, self::RAM_LINE_QUANTITY);
        $this->tritaniumLine = $this->materialLine($this->ishtarProject, self::TRITANIUM_LINE_QUANTITY);

        $this->hecateProject = $this->projectOfTheOwner('Hecate batch');
        $this->participate($this->hecateProject, $this->owner, ParticipantRole::Owner);
        $this->hecateTritaniumLine = $this->materialLine($this->hecateProject, 40_000);
    }

    private function characterOf(User $user, string $name): Character
    {
        $character = (new Character())->setName($name);
        (new \ReflectionProperty(Character::class, 'id'))->setValue($character, Uuid::v4());
        $user->addCharacter($character);

        return $character;
    }

    private function projectOfTheOwner(string $name): IndustryProject
    {
        $project = (new IndustryProject())->setOwner($this->owner)->setName($name);
        (new \ReflectionProperty(IndustryProject::class, 'createdAt'))->setValue($project, new \DateTimeImmutable('2026-11-01T10:00:00+00:00'));

        return $project;
    }

    private function participate(IndustryProject $project, User $user, ParticipantRole $role): void
    {
        $participant = (new IndustryProjectParticipant())->setUser($user)->setRole($role)->setStatus(ParticipantStatus::Accepted);
        $project->addMember($participant);
        $this->participants[] = $participant;
    }

    private function jobLine(IndustryProject $project, int $typeId, string $typeName, string $jobGroup, int $runs, int $quantity): IndustryProjectBomLine
    {
        $line = (new IndustryProjectBomLine())
            ->setTypeId($typeId)
            ->setTypeName($typeName)
            ->setRequiredQuantity($quantity)
            ->setIsJob(true)
            ->setJobGroup($jobGroup)
            ->setActivityType('manufacturing')
            ->setRuns($runs);
        $project->addBomItem($line);

        return $line;
    }

    private function materialLine(IndustryProject $project, int $quantity): IndustryProjectBomLine
    {
        $line = (new IndustryProjectBomLine())
            ->setTypeId(self::TRITANIUM_TYPE_ID)
            ->setTypeName('Tritanium')
            ->setRequiredQuantity($quantity)
            ->setEstimatedPrice(4.25)
            ->setIsJob(false);
        $project->addBomItem($line);

        return $line;
    }

    private function securityOf(User $user): Security
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);

        return $security;
    }

    private function projectRepository(): IndustryProjectRepository
    {
        $repository = $this->createStub(IndustryProjectRepository::class);
        $repository->method('find')->willReturnCallback(
            fn (Uuid $id): ?IndustryProject => array_find(
                [$this->ishtarProject, $this->hecateProject],
                static fn (IndustryProject $project): bool => $project->getId()->equals($id),
            ),
        );

        return $repository;
    }

    private function bomLineRepository(): IndustryProjectBomLineRepository
    {
        $repository = $this->createStub(IndustryProjectBomLineRepository::class);
        $repository->method('find')->willReturnCallback(
            fn (Uuid $id): ?IndustryProjectBomLine => array_find(
                [$this->ishtarLine, $this->ramLine, $this->tritaniumLine, $this->hecateTritaniumLine],
                static fn (IndustryProjectBomLine $line): bool => $line->getId()->equals($id),
            ),
        );

        return $repository;
    }

    private function accessChecker(): ProjectAccessChecker
    {
        $repository = $this->createStub(IndustryProjectParticipantRepository::class);
        $repository->method('findOneBy')->willReturnCallback(
            fn (array $criteria): ?IndustryProjectParticipant => array_find(
                $this->participants,
                static fn (IndustryProjectParticipant $participant): bool => ($criteria['project'] ?? $participant->getProject()) === $participant->getProject()
                    && ($criteria['user'] ?? $participant->getUser()) === $participant->getUser()
                    && ($criteria['status'] ?? $participant->getStatus()) === $participant->getStatus()
                    && ($criteria['role'] ?? $participant->getRole()) === $participant->getRole(),
            ),
        );

        return new ProjectAccessChecker($repository);
    }

    private function outcomeOf(callable $operation): string
    {
        try {
            $operation();
        } catch (HttpExceptionInterface $exception) {
            return $exception->getStatusCode().' '.$exception->getMessage();
        }

        return self::GRANTED;
    }

    private function lineUriVariables(IndustryProject $project, IndustryProjectBomLine $line): array
    {
        return ['projectId' => $project->getId()->toRfc4122(), 'lineId' => $line->getId()->toRfc4122()];
    }
}
