<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project;

use App\Entity\Character;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectContribution;
use App\Entity\IndustryProjectJobMatch;
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
#[Group('issue-1329')]
#[AllowMockObjectsWithoutExpectations]
final class ProjectContributionServiceJobFulfillmentTest extends TestCase
{
    private const int HOBGOBLIN_TYPE_ID = 2454;
    private const int TRITANIUM_TYPE_ID = 34;
    private const int HOBGOBLIN_RUNS = 10;
    private const int HOBGOBLIN_QUANTITY = 50;
    private const int MANUAL_RUNS = 6;
    private const int MANUAL_RUNS_IN_UNITS = 30;
    private const string INSTALLER_CHARACTER_NAME = 'Sana Vel';
    private const float JOB_INSTALL_COST = 180_000.0;

    private EntityManagerInterface&MockObject $entityManager;
    private ProjectContributionService $contributions;
    private DerivedContributionService $derivedContributions;
    private IndustryProject $project;
    private IndustryProjectParticipant $installerParticipant;
    private User $owner;

    protected function setUp(): void
    {
        $this->owner = new User();
        $this->owner->addCharacter((new Character())->setEveCharacterId(2_329_000_001)->setName('Aria Solberg'));
        $installerUser = new User();
        $installerUser->addCharacter((new Character())->setEveCharacterId(2_329_000_002)->setName(self::INSTALLER_CHARACTER_NAME));

        $this->project = (new IndustryProject())->setOwner($this->owner);
        $this->project->enrolOwner();
        $this->project->setStatus(ProjectStatus::InProgress);
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
        });
        $this->entityManager->method('wrapInTransaction')->willReturnCallback(static fn (callable $work): mixed => $work());

        $lineReservations = new LineReservationService($this->createStub(IndustryProjectLineReservationRepository::class), $this->entityManager);

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

    public function testApprovedManualJobFillsTheLineInUnitsNotInRuns(): void
    {
        $line = $this->hobgoblinLine();
        $contribution = $this->manualContribution($line, ContributionType::JobInstall, self::MANUAL_RUNS);

        $this->contributions->approve($contribution, $this->owner);

        $this->assertSame(self::MANUAL_RUNS_IN_UNITS, $line->getFulfilledQuantity());
    }

    public function testConfirmedManualJobFillsTheLineInUnitsNotInRuns(): void
    {
        $line = $this->hobgoblinLine();
        $contribution = $this->manualContribution($line, ContributionType::JobInstall, self::MANUAL_RUNS);

        $this->contributions->confirmDelivery($contribution);

        $this->assertSame(self::MANUAL_RUNS_IN_UNITS, $line->getFulfilledQuantity());
    }

    public function testManualJobOfAllTheRunsFillsTheWholeLine(): void
    {
        $line = $this->hobgoblinLine();
        $contribution = $this->manualContribution($line, ContributionType::JobInstall, self::HOBGOBLIN_RUNS);

        $this->contributions->approve($contribution, $this->owner);

        $this->assertSame(self::HOBGOBLIN_QUANTITY, $line->getFulfilledQuantity());
    }

    public function testDerivedJobAfterTheManualOneDoesNotFillTheLineAgain(): void
    {
        $line = $this->hobgoblinLine();
        $contribution = $this->manualContribution($line, ContributionType::JobInstall, self::MANUAL_RUNS);
        $this->contributions->approve($contribution, $this->owner);

        $line->addJobMatch(
            (new IndustryProjectJobMatch())
                ->setEsiJobId(6_329_000_001)
                ->setRuns(self::MANUAL_RUNS)
                ->setCost(self::JOB_INSTALL_COST)
                ->setCharacterName(self::INSTALLER_CHARACTER_NAME),
        );
        $this->derivedContributions->refreshLine($line);

        $this->assertSame(self::MANUAL_RUNS_IN_UNITS, $line->getFulfilledQuantity());
    }

    public function testManualMaterialContributionStillFillsTheLineByItsQuantity(): void
    {
        $tritaniumLine = (new IndustryProjectBomLine())
            ->setTypeId(self::TRITANIUM_TYPE_ID)
            ->setTypeName('Tritanium')
            ->setRequiredQuantity(1_000)
            ->setIsJob(false);
        $this->project->addBomItem($tritaniumLine);
        $contribution = $this->manualContribution($tritaniumLine, ContributionType::Material, 300);

        $this->contributions->approve($contribution, $this->owner);

        $this->assertSame(300, $tritaniumLine->getFulfilledQuantity());
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

    private function manualContribution(IndustryProjectBomLine $line, ContributionType $type, int $quantity): IndustryProjectContribution
    {
        $contribution = (new IndustryProjectContribution())
            ->setProject($this->project)
            ->setMember($this->installerParticipant)
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
}
