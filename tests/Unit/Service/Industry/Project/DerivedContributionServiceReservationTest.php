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
use App\Entity\IndustryProjectPurchase;
use App\Entity\User;
use App\Enum\ContributionType;
use App\Enum\ParticipantRole;
use App\Enum\ParticipantStatus;
use App\Repository\IndustryProjectLineReservationRepository;
use App\Service\Industry\Project\DerivedContributionService;
use App\Service\Industry\Project\LineReservationService;
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

#[CoversClass(DerivedContributionService::class)]
#[Group('issue-1202')]
#[AllowMockObjectsWithoutExpectations]
final class DerivedContributionServiceReservationTest extends TestCase
{
    private const int ISHTAR_TYPE_ID = 12005;
    private const int HOBGOBLIN_TYPE_ID = 2454;
    private const int CRYSTALLINE_CARBONIDE_TYPE_ID = 16670;
    private const int TRITANIUM_TYPE_ID = 34;
    private const string OWNER_CHARACTER_NAME = 'Aria Solberg';
    private const string INSTALLER_CHARACTER_NAME = 'Sana Vel';

    private EntityManagerInterface&MockObject $entityManager;
    private DerivedContributionService $service;
    private User $owner;
    private User $installerUser;
    private IndustryProject $project;
    private IndustryProjectParticipant $ownerParticipant;
    private IndustryProjectParticipant $installerParticipant;
    private array $reservations = [];
    private int $esiJobSequence = 6_202_000_000;

    protected function setUp(): void
    {
        $this->owner = new User();
        $this->owner->addCharacter((new Character())->setEveCharacterId(2_202_000_001)->setName(self::OWNER_CHARACTER_NAME));
        $this->installerUser = new User();
        $this->installerUser->addCharacter((new Character())->setEveCharacterId(2_202_000_002)->setName(self::INSTALLER_CHARACTER_NAME));

        $this->project = (new IndustryProject())->setOwner($this->owner);
        $this->project->enrolOwner();
        $this->ownerParticipant = $this->project->getMembers()->first();
        $this->installerParticipant = (new IndustryProjectParticipant())
            ->setUser($this->installerUser)
            ->setRole(ParticipantRole::Member)
            ->setStatus(ParticipantStatus::Accepted);
        $this->project->addMember($this->installerParticipant);

        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->entityManager->method('persist')->willReturnCallback(function (object $entity): void {
            if ($entity instanceof IndustryProjectContribution) {
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

        $hub = $this->createMock(HubInterface::class);
        $hub->method('publish')->willReturn('update-id');

        $this->service = new DerivedContributionService(
            $this->entityManager,
            new MercurePublisherService($hub, new NullLogger()),
            $this->createMock(NotificationDispatcher::class),
            new LineReservationService($reservationRepository, $this->entityManager),
        );
    }

    public function testJobCoveringTheWholeReservedLineSettlesTheInstallersReservation(): void
    {
        $ishtarLine = $this->manufacturingLine(self::ISHTAR_TYPE_ID, 10, 10);
        $this->reserve($ishtarLine, $this->installerParticipant, 10);
        $this->linkJobStartedBy($ishtarLine, 10, self::INSTALLER_CHARACTER_NAME);

        $this->service->refreshLine($ishtarLine);

        $this->assertNull($this->reservedQuantityOf($ishtarLine, $this->installerParticipant));
        $this->assertSame(0, $ishtarLine->getFulfilledQuantity());
    }

    public function testPartialJobLeavesTheRunsNotYetInstalledAsTheReservation(): void
    {
        $ishtarLine = $this->manufacturingLine(self::ISHTAR_TYPE_ID, 10, 10);
        $this->reserve($ishtarLine, $this->installerParticipant, 10);
        $this->linkJobStartedBy($ishtarLine, 4, self::INSTALLER_CHARACTER_NAME);

        $this->service->refreshLine($ishtarLine);

        $this->assertSame(6, $this->reservedQuantityOf($ishtarLine, $this->installerParticipant));
    }

    public function testRefreshingAgainWithoutAnyChangeOfRunsReducesNothingMore(): void
    {
        $ishtarLine = $this->manufacturingLine(self::ISHTAR_TYPE_ID, 10, 10);
        $this->reserve($ishtarLine, $this->installerParticipant, 10);
        $this->linkJobStartedBy($ishtarLine, 4, self::INSTALLER_CHARACTER_NAME);
        $this->service->refreshLine($ishtarLine);

        $this->service->refreshLine($ishtarLine);
        $this->service->refreshLine($ishtarLine);

        $this->assertSame(6, $this->reservedQuantityOf($ishtarLine, $this->installerParticipant));
    }

    public function testSecondJobOfTheInstallerReducesTheReservationByItsOwnRunsOnlyThenSettlesIt(): void
    {
        $ishtarLine = $this->manufacturingLine(self::ISHTAR_TYPE_ID, 10, 10);
        $this->reserve($ishtarLine, $this->installerParticipant, 10);
        $this->linkJobStartedBy($ishtarLine, 4, self::INSTALLER_CHARACTER_NAME);
        $this->service->refreshLine($ishtarLine);

        $this->linkJobStartedBy($ishtarLine, 2, self::INSTALLER_CHARACTER_NAME);
        $this->service->refreshLine($ishtarLine);

        $this->assertSame(4, $this->reservedQuantityOf($ishtarLine, $this->installerParticipant));

        $this->linkJobStartedBy($ishtarLine, 4, self::INSTALLER_CHARACTER_NAME);
        $this->service->refreshLine($ishtarLine);

        $this->assertNull($this->reservedQuantityOf($ishtarLine, $this->installerParticipant));
    }

    public function testRunsOfSeveralJobsLinkedInTheSamePassAreSettledOnce(): void
    {
        $ishtarLine = $this->manufacturingLine(self::ISHTAR_TYPE_ID, 10, 10);
        $this->reserve($ishtarLine, $this->installerParticipant, 10);
        $this->linkJobStartedBy($ishtarLine, 4, self::INSTALLER_CHARACTER_NAME);
        $this->linkJobStartedBy($ishtarLine, 3, self::INSTALLER_CHARACTER_NAME);

        $this->service->refreshLine($ishtarLine);

        $this->assertSame(3, $this->reservedQuantityOf($ishtarLine, $this->installerParticipant));
    }

    public function testReservationIsReducedByTheQuantityTheRunsProduceNotByTheRuns(): void
    {
        $hobgoblinLine = $this->manufacturingLine(self::HOBGOBLIN_TYPE_ID, 10, 50);
        $this->reserve($hobgoblinLine, $this->installerParticipant, 50);
        $this->linkJobStartedBy($hobgoblinLine, 4, self::INSTALLER_CHARACTER_NAME);

        $this->service->refreshLine($hobgoblinLine);

        $this->assertSame(30, $this->reservedQuantityOf($hobgoblinLine, $this->installerParticipant));
    }

    public function testJobOfTheInstallerLeavesTheOwnersReservationOnTheSameLineUntouched(): void
    {
        $ishtarLine = $this->manufacturingLine(self::ISHTAR_TYPE_ID, 10, 10);
        $this->reserve($ishtarLine, $this->installerParticipant, 10);
        $this->reserve($ishtarLine, $this->ownerParticipant, 5);
        $this->linkJobStartedBy($ishtarLine, 10, self::INSTALLER_CHARACTER_NAME);

        $this->service->refreshLine($ishtarLine);

        $this->assertNull($this->reservedQuantityOf($ishtarLine, $this->installerParticipant));
        $this->assertSame(5, $this->reservedQuantityOf($ishtarLine, $this->ownerParticipant));
    }

    public function testJobLinkedByHandSettlesTheReservationOfTheCharacterOwnerNotOfWhoLinkedIt(): void
    {
        $ishtarLine = $this->manufacturingLine(self::ISHTAR_TYPE_ID, 10, 10);
        $this->reserve($ishtarLine, $this->installerParticipant, 10);
        $this->reserve($ishtarLine, $this->ownerParticipant, 7);
        $this->linkJobStartedBy($ishtarLine, 10, self::INSTALLER_CHARACTER_NAME)->setManual(true);

        $this->service->refreshLine($ishtarLine);

        $this->assertNull($this->reservedQuantityOf($ishtarLine, $this->installerParticipant));
        $this->assertSame(7, $this->reservedQuantityOf($ishtarLine, $this->ownerParticipant));
    }

    public function testRemovingTheLinkOfAFullyInstalledLineDoesNotRestoreTheReservation(): void
    {
        $ishtarLine = $this->manufacturingLine(self::ISHTAR_TYPE_ID, 10, 10);
        $this->reserve($ishtarLine, $this->installerParticipant, 10);
        $jobMatch = $this->linkJobStartedBy($ishtarLine, 10, self::INSTALLER_CHARACTER_NAME);
        $this->service->refreshLine($ishtarLine);

        $ishtarLine->getJobMatches()->removeElement($jobMatch);
        $this->service->refreshLine($ishtarLine);

        $this->assertNull($this->reservedQuantityOf($ishtarLine, $this->installerParticipant));
    }

    public function testReactionLineSettlesTheReservationWithTheQuantityOfItsOutput(): void
    {
        $carbonideLine = $this->reactionLine(self::CRYSTALLINE_CARBONIDE_TYPE_ID, 5, 1_000);
        $this->reserve($carbonideLine, $this->installerParticipant, 600);
        $this->linkJobStartedBy($carbonideLine, 5, self::INSTALLER_CHARACTER_NAME);

        $this->service->refreshLine($carbonideLine);

        $this->assertNull($this->reservedQuantityOf($carbonideLine, $this->installerParticipant));
    }

    public function testInstallerWithoutReservationOnTheLineGetsItsDerivedContributionAndNothingElseChanges(): void
    {
        $ishtarLine = $this->manufacturingLine(self::ISHTAR_TYPE_ID, 10, 10);
        $this->reserve($ishtarLine, $this->ownerParticipant, 3);
        $this->linkJobStartedBy($ishtarLine, 10, self::INSTALLER_CHARACTER_NAME);

        $this->service->refreshLine($ishtarLine);

        $this->assertNull($this->reservedQuantityOf($ishtarLine, $this->installerParticipant));
        $this->assertSame(3, $this->reservedQuantityOf($ishtarLine, $this->ownerParticipant));
        $jobInstalls = array_values(array_filter(
            $this->project->getContributions()->toArray(),
            static fn (IndustryProjectContribution $contribution): bool => ContributionType::JobInstall === $contribution->getType(),
        ));
        $this->assertCount(1, $jobInstalls);
        $this->assertSame([$this->installerParticipant, 10], [$jobInstalls[0]->getMember(), $jobInstalls[0]->getQuantity()]);
    }

    public function testMaterialPurchaseOfTheOwnerLeavesTheMaterialReservationUntouched(): void
    {
        $tritaniumLine = (new IndustryProjectBomLine())
            ->setTypeId(self::TRITANIUM_TYPE_ID)
            ->setTypeName('Tritanium')
            ->setRequiredQuantity(1_000)
            ->setIsJob(false);
        $this->project->addBomItem($tritaniumLine);
        $this->reserve($tritaniumLine, $this->ownerParticipant, 800);
        $tritaniumLine->addPurchase(
            (new IndustryProjectPurchase())
                ->setTypeId(self::TRITANIUM_TYPE_ID)
                ->setQuantity(400)
                ->setUnitPrice(6.5)
                ->setTotalPrice(2600.0)
                ->setSource('esi_wallet'),
        );

        $this->service->refreshLine($tritaniumLine);

        $this->assertSame(800, $this->reservedQuantityOf($tritaniumLine, $this->ownerParticipant));
    }

    public function testRunsRemovedFromALinkedJobDoNotRestoreTheReservation(): void
    {
        $ishtarLine = $this->manufacturingLine(self::ISHTAR_TYPE_ID, 10, 10);
        $this->reserve($ishtarLine, $this->installerParticipant, 10);
        $this->linkJobStartedBy($ishtarLine, 4, self::INSTALLER_CHARACTER_NAME);
        $removedJobMatch = $this->linkJobStartedBy($ishtarLine, 3, self::INSTALLER_CHARACTER_NAME);
        $this->service->refreshLine($ishtarLine);

        $ishtarLine->getJobMatches()->removeElement($removedJobMatch);
        $this->service->refreshLine($ishtarLine);

        $this->assertSame(3, $this->reservedQuantityOf($ishtarLine, $this->installerParticipant));
    }

    public function testChangeOfCostWithTheSameRunsKeepsTheReservation(): void
    {
        $ishtarLine = $this->manufacturingLine(self::ISHTAR_TYPE_ID, 10, 10);
        $this->reserve($ishtarLine, $this->installerParticipant, 10);
        $jobMatch = $this->linkJobStartedBy($ishtarLine, 4, self::INSTALLER_CHARACTER_NAME);
        $this->service->refreshLine($ishtarLine);

        $jobMatch->setCost(250_000.0);
        $this->service->refreshLine($ishtarLine);

        $this->assertSame(6, $this->reservedQuantityOf($ishtarLine, $this->installerParticipant));
    }

    public function testOneRunInstalledReducesTheReservationByOneRun(): void
    {
        $ishtarLine = $this->manufacturingLine(self::ISHTAR_TYPE_ID, 10, 10);
        $this->reserve($ishtarLine, $this->installerParticipant, 10);
        $this->linkJobStartedBy($ishtarLine, 1, self::INSTALLER_CHARACTER_NAME);

        $this->service->refreshLine($ishtarLine);

        $this->assertSame(9, $this->reservedQuantityOf($ishtarLine, $this->installerParticipant));
    }

    public function testRoundingNeverLeavesAReservationOnAFullyInstalledLine(): void
    {
        $line = $this->manufacturingLine(self::ISHTAR_TYPE_ID, 3, 25);
        $this->reserve($line, $this->installerParticipant, 25);
        foreach ([1, 1, 1] as $runs) {
            $this->linkJobStartedBy($line, $runs, self::INSTALLER_CHARACTER_NAME);
            $this->service->refreshLine($line);
        }

        $this->assertNull($this->reservedQuantityOf($line, $this->installerParticipant));
    }

    public function testJobOfAnUnknownCharacterSettlesTheOwnersReservation(): void
    {
        $ishtarLine = $this->manufacturingLine(self::ISHTAR_TYPE_ID, 10, 10);
        $this->reserve($ishtarLine, $this->ownerParticipant, 10);
        $this->reserve($ishtarLine, $this->installerParticipant, 10);
        $this->linkJobStartedBy($ishtarLine, 4, 'Unknown Pilot');

        $this->service->refreshLine($ishtarLine);

        $this->assertSame(6, $this->reservedQuantityOf($ishtarLine, $this->ownerParticipant));
        $this->assertSame(10, $this->reservedQuantityOf($ishtarLine, $this->installerParticipant));
    }

    public function testLinkedJobOnALineCoveredByStockDoesNotThrow(): void
    {
        $line = $this->manufacturingLine(self::ISHTAR_TYPE_ID, 0, 10);
        $this->linkJobStartedBy($line, 2, self::INSTALLER_CHARACTER_NAME);

        $this->service->refreshLine($line);

        $this->assertCount(1, array_filter(
            $this->project->getContributions()->toArray(),
            static fn (IndustryProjectContribution $contribution): bool => ContributionType::JobInstall === $contribution->getType(),
        ));
    }

    private function manufacturingLine(int $typeId, int $runs, int $requiredQuantity): IndustryProjectBomLine
    {
        return $this->jobLine($typeId, 'manufacturing', $runs, $requiredQuantity);
    }

    private function reactionLine(int $typeId, int $runs, int $requiredQuantity): IndustryProjectBomLine
    {
        return $this->jobLine($typeId, 'reaction', $runs, $requiredQuantity);
    }

    private function jobLine(int $typeId, string $activityType, int $runs, int $requiredQuantity): IndustryProjectBomLine
    {
        $line = (new IndustryProjectBomLine())
            ->setTypeId($typeId)
            ->setTypeName('Type '.$typeId)
            ->setRequiredQuantity($requiredQuantity)
            ->setIsJob(true)
            ->setJobGroup('final')
            ->setActivityType($activityType)
            ->setRuns($runs);
        $this->project->addBomItem($line);

        return $line;
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

    private function linkJobStartedBy(IndustryProjectBomLine $line, int $runs, string $characterName): IndustryProjectJobMatch
    {
        $jobMatch = (new IndustryProjectJobMatch())
            ->setEsiJobId(++$this->esiJobSequence)
            ->setRuns($runs)
            ->setCost(180_000.0 + $runs)
            ->setCharacterName($characterName);
        $line->addJobMatch($jobMatch);

        return $jobMatch;
    }
}
