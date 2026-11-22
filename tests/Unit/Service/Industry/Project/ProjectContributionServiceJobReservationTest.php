<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project;

use App\Entity\Character;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectContribution;
use App\Entity\IndustryProjectJobMatch;
use App\Entity\IndustryProjectLineReservation;
use App\Entity\IndustryProjectParticipant;
use App\Entity\User;
use App\Enum\ContributionStatus;
use App\Enum\ContributionType;
use App\Enum\ParticipantRole;
use App\Enum\ParticipantStatus;
use App\Enum\ProjectStatus;
use App\Repository\IndustryProjectContributionRepository;
use App\Repository\IndustryProjectLineReservationRepository;
use App\Service\Industry\Project\ContributorPaidPurchaseReader;
use App\Service\Industry\Project\DerivedContributionService;
use App\Service\Industry\Project\IndustryProjectService;
use App\Service\Industry\Project\LineReservationService;
use App\Service\Industry\Project\ProjectContributionService;
use App\Service\JitaMarketService;
use App\Service\Mercure\MercurePublisherService;
use App\Service\Notification\NotificationDispatcher;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Mercure\HubInterface;

#[CoversClass(ProjectContributionService::class)]
#[CoversClass(DerivedContributionService::class)]
#[Group('issue-1323')]
#[AllowMockObjectsWithoutExpectations]
final class ProjectContributionServiceJobReservationTest extends TestCase
{
    private const int HOBGOBLIN_TYPE_ID = 2454;
    private const int TRITANIUM_TYPE_ID = 34;
    private const int HOBGOBLIN_RUNS = 10;
    private const int HOBGOBLIN_QUANTITY = 50;
    private const string INSTALLER_CHARACTER_NAME = 'Sana Vel';
    private const float JOB_INSTALL_COST = 180_000.0;

    private EntityManagerInterface&MockObject $entityManager;
    private ProjectContributionService $contributions;
    private DerivedContributionService $derivedContributions;
    private IndustryProject $project;
    private IndustryProjectParticipant $ownerParticipant;
    private IndustryProjectParticipant $installerParticipant;
    private User $owner;
    private array $reservations = [];
    private int $esiJobSequence = 6_323_000_000;

    protected function setUp(): void
    {
        $this->owner = new User();
        $this->owner->addCharacter((new Character())->setEveCharacterId(2_323_000_001)->setName('Aria Solberg'));
        $installerUser = new User();
        $installerUser->addCharacter((new Character())->setEveCharacterId(2_323_000_002)->setName(self::INSTALLER_CHARACTER_NAME));

        $this->project = (new IndustryProject())->setOwner($this->owner);
        $this->project->enrolOwner();
        $this->project->setStatus(ProjectStatus::InProgress);
        $this->ownerParticipant = $this->project->getMembers()->first();
        $this->installerParticipant = (new IndustryProjectParticipant())
            ->setUser($installerUser)
            ->setRole(ParticipantRole::Member)
            ->setStatus(ParticipantStatus::Accepted);
        $this->project->addMember($this->installerParticipant);

        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->entityManager->method('persist')->willReturnCallback(function (object $entity): void {
            if ($entity instanceof IndustryProjectContribution && !$this->project->getContributions()->contains($entity)) {
                $this->project->addContribution($entity);
            }
        });
        $this->entityManager->method('remove')->willReturnCallback(function (object $entity): void {
            if ($entity instanceof IndustryProjectContribution) {
                $this->project->getContributions()->removeElement($entity);
            }
            if ($entity instanceof IndustryProjectLineReservation) {
                $this->reservations = array_values(array_filter(
                    $this->reservations,
                    static fn (IndustryProjectLineReservation $reservation): bool => $reservation !== $entity,
                ));
            }
        });

        $reservationRepository = $this->createMock(IndustryProjectLineReservationRepository::class);
        $reservationRepository->method('findOneByLineAndParticipant')->willReturnCallback(
            function (IndustryProjectBomLine $line, IndustryProjectParticipant $participant): ?IndustryProjectLineReservation {
                foreach ($this->reservations as $reservation) {
                    if ($reservation->getBomLine() === $line && $reservation->getParticipant() === $participant) {
                        return $reservation;
                    }
                }

                return null;
            },
        );
        $reservationRepository->method('deleteByLine')->willReturnCallback(
            function (IndustryProjectBomLine $line): void {
                $this->reservations = array_values(array_filter(
                    $this->reservations,
                    static fn (IndustryProjectLineReservation $reservation): bool => $reservation->getBomLine() !== $line,
                ));
            },
        );
        $lineReservations = new LineReservationService($reservationRepository, $this->entityManager);

        $this->contributions = new ProjectContributionService(
            $this->createStub(IndustryProjectContributionRepository::class),
            $this->entityManager,
            $this->createStub(JitaMarketService::class),
            $lineReservations,
            $this->createStub(IndustryProjectService::class),
            $this->createStub(ContributorPaidPurchaseReader::class),
        );

        $hub = $this->createMock(HubInterface::class);
        $hub->method('publish')->willReturn('update-id');
        $this->derivedContributions = new DerivedContributionService(
            $this->entityManager,
            new MercurePublisherService($hub, new NullLogger()),
            $this->createMock(NotificationDispatcher::class),
            $lineReservations,
        );
    }

    public function testApprovedManualJobSettlesTheReservationInLineQuantityNotInRuns(): void
    {
        $line = $this->hobgoblinLine();
        $this->reserve($line, $this->installerParticipant, self::HOBGOBLIN_QUANTITY);

        $this->approveManualJob($line, 4);

        $this->assertSame(30, $this->reservedQuantityOf($line, $this->installerParticipant));
    }

    public function testManualJobOfAllTheRunsSettlesTheWholeReservation(): void
    {
        $line = $this->hobgoblinLine();
        $this->reserve($line, $this->installerParticipant, self::HOBGOBLIN_QUANTITY);

        $this->approveManualJob($line, self::HOBGOBLIN_RUNS);

        $this->assertNull($this->reservedQuantityOf($line, $this->installerParticipant));
    }

    public function testManualJobThenDerivedJobOfTheSameRunsReducesTheReservationOnce(): void
    {
        $line = $this->hobgoblinLine();
        $this->reserve($line, $this->installerParticipant, self::HOBGOBLIN_QUANTITY);
        $this->approveManualJob($line, 4);

        $this->linkJobStartedByTheInstaller($line, 4);
        $this->derivedContributions->refreshLine($line);

        $this->assertSame(30, $this->reservedQuantityOf($line, $this->installerParticipant));
    }

    public function testDerivedJobThenManualJobOfTheSameRunsReducesTheReservationOnce(): void
    {
        $line = $this->hobgoblinLine();
        $this->reserve($line, $this->installerParticipant, self::HOBGOBLIN_QUANTITY);
        $this->linkJobStartedByTheInstaller($line, 4);
        $this->derivedContributions->refreshLine($line);

        $this->approveManualJob($line, 4);

        $this->assertSame(30, $this->reservedQuantityOf($line, $this->installerParticipant));
    }

    public function testDerivedJobOfMoreRunsThanTheManualOneSettlesOnlyTheExtraRuns(): void
    {
        $line = $this->hobgoblinLine();
        $this->reserve($line, $this->installerParticipant, self::HOBGOBLIN_QUANTITY);
        $this->approveManualJob($line, 4);

        $this->linkJobStartedByTheInstaller($line, 6);
        $this->derivedContributions->refreshLine($line);

        $this->assertSame(20, $this->reservedQuantityOf($line, $this->installerParticipant));
    }

    public function testManualJobOfMoreRunsThanTheDerivedOneSettlesOnlyTheExtraRuns(): void
    {
        $line = $this->hobgoblinLine();
        $this->reserve($line, $this->installerParticipant, self::HOBGOBLIN_QUANTITY);
        $this->linkJobStartedByTheInstaller($line, 4);
        $this->derivedContributions->refreshLine($line);

        $this->approveManualJob($line, 6);

        $this->assertSame(20,$this->reservedQuantityOf($line, $this->installerParticipant));
    }

    public function testRefreshingTheDerivedJobAgainAfterTheManualOneReducesNothingMore(): void
    {
        $line = $this->hobgoblinLine();
        $this->reserve($line, $this->installerParticipant, self::HOBGOBLIN_QUANTITY);
        $this->approveManualJob($line, 4);
        $this->linkJobStartedByTheInstaller($line, 4);
        $this->derivedContributions->refreshLine($line);

        $this->derivedContributions->refreshLine($line);

        $this->assertSame(30, $this->reservedQuantityOf($line, $this->installerParticipant));
    }

    public function testManualJobOfAnotherParticipantLeavesTheInstallersReservationUntouched(): void
    {
        $line = $this->hobgoblinLine();
        $this->reserve($line, $this->installerParticipant, self::HOBGOBLIN_QUANTITY);
        $this->reserve($line, $this->ownerParticipant, 20);

        $this->approveManualJob($line, 4, $this->ownerParticipant);

        $this->assertSame(self::HOBGOBLIN_QUANTITY, $this->reservedQuantityOf($line, $this->installerParticipant));
        $this->assertNull($this->reservedQuantityOf($line, $this->ownerParticipant));
    }

    public function testManualMaterialContributionStillSettlesTheReservationByItsQuantity(): void
    {
        $tritaniumLine = (new IndustryProjectBomLine())
            ->setTypeId(self::TRITANIUM_TYPE_ID)
            ->setTypeName('Tritanium')
            ->setRequiredQuantity(1_000)
            ->setIsJob(false);
        $this->project->addBomItem($tritaniumLine);
        $this->reserve($tritaniumLine, $this->installerParticipant, 800);
        $contribution = $this->manualContribution($tritaniumLine, ContributionType::Material, 300, $this->installerParticipant);

        $this->contributions->approve($contribution, $this->owner);

        $this->assertSame(500, $this->reservedQuantityOf($tritaniumLine, $this->installerParticipant));
    }

    public function testConfirmedManualJobSettlesTheReservationInLineQuantity(): void
    {
        $line = $this->hobgoblinLine();
        $this->reserve($line, $this->installerParticipant, self::HOBGOBLIN_QUANTITY);
        $contribution = $this->manualContribution($line, ContributionType::JobInstall, 4, $this->installerParticipant);

        $this->contributions->confirmDelivery($contribution);

        $this->assertSame(30, $this->reservedQuantityOf($line, $this->installerParticipant));
    }

    private function hobgoblinLine(): IndustryProjectBomLine
    {
        $line = (new IndustryProjectBomLine())
            ->setTypeId(self::HOBGOBLIN_TYPE_ID)
            ->setTypeName('Hobgoblin I')
            ->setRequiredQuantity(self::HOBGOBLIN_QUANTITY)
            ->setIsJob(true)
            ->setJobGroup('final')
            ->setActivityType('manufacturing')
            ->setRuns(self::HOBGOBLIN_RUNS);
        $this->project->addBomItem($line);

        return $line;
    }

    private function approveManualJob(IndustryProjectBomLine $line, int $runs, ?IndustryProjectParticipant $installer = null): void
    {
        $contribution = $this->manualContribution($line, ContributionType::JobInstall, $runs, $installer ?? $this->installerParticipant);

        $this->contributions->approve($contribution, $this->owner);
    }

    private function manualContribution(IndustryProjectBomLine $line, ContributionType $type, int $quantity, IndustryProjectParticipant $member): IndustryProjectContribution
    {
        $contribution = (new IndustryProjectContribution())
            ->setProject($this->project)
            ->setMember($member)
            ->setBomItem($line)
            ->setType($type)
            ->setQuantity($quantity)
            ->setEstimatedValue(self::JOB_INSTALL_COST)
            ->setStatus(ContributionStatus::Pending)
            ->setIsAutoDetected(false)
            ->setIsVerified(false)
            ->setAwaitingQuantity(0);
        $this->project->addContribution($contribution);

        return $contribution;
    }

    private function linkJobStartedByTheInstaller(IndustryProjectBomLine $line, int $runs): void
    {
        $line->addJobMatch(
            (new IndustryProjectJobMatch())
                ->setEsiJobId(++$this->esiJobSequence)
                ->setRuns($runs)
                ->setCost(self::JOB_INSTALL_COST + $runs)
                ->setCharacterName(self::INSTALLER_CHARACTER_NAME),
        );
    }

    private function reserve(IndustryProjectBomLine $line, IndustryProjectParticipant $participant, int $quantity): void
    {
        $this->reservations[] = new IndustryProjectLineReservation($line, $participant, $quantity);
    }

    private function reservedQuantityOf(IndustryProjectBomLine $line, IndustryProjectParticipant $participant): ?int
    {
        foreach ($this->reservations as $reservation) {
            if ($reservation->getBomLine() === $line && $reservation->getParticipant() === $participant) {
                return $reservation->getQuantity();
            }
        }

        return null;
    }
}
