<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectLineReservation;
use App\Entity\IndustryProjectParticipant;
use App\Entity\User;
use App\Enum\ParticipantRole;
use App\Enum\ParticipantStatus;
use App\Repository\IndustryProjectLineReservationRepository;
use App\Service\Industry\Project\LineReservationService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(LineReservationService::class)]
#[Group('issue-1301')]
final class LineReservationServiceExpiryTest extends TestCase
{
    private const int FEROX_TYPE_ID = 16_227;
    private const int FEROX_REQUIRED_QUANTITY = 10;

    private IndustryProjectBomLine $line;
    private IndustryProjectParticipant $sana;
    private IndustryProjectParticipant $maya;
    private IndustryProjectLineReservationRepository&MockObject $reservationRepository;
    private EntityManagerInterface&MockObject $entityManager;
    private LineReservationService $service;

    protected function setUp(): void
    {
        $project = (new IndustryProject())->setOwner(new User());
        $this->line = (new IndustryProjectBomLine())
            ->setTypeId(self::FEROX_TYPE_ID)
            ->setTypeName('Ferox')
            ->setRequiredQuantity(self::FEROX_REQUIRED_QUANTITY);
        $project->addBomItem($this->line);
        $this->sana = $this->acceptedParticipantOf($project);
        $this->maya = $this->acceptedParticipantOf($project);

        $this->reservationRepository = $this->createMock(IndustryProjectLineReservationRepository::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->service = new LineReservationService($this->reservationRepository, $this->entityManager);
    }

    public function testNewReservationExpiresFortyEightHoursAfterItWasMade(): void
    {
        $this->reservationRepository->method('findOneIncludingExpiredByLineAndParticipant')->willReturn(null);
        $this->reservationRepository->method('sumQuantityOfOthers')->willReturn(0);
        $persisted = [];
        $this->entityManager->method('persist')->willReturnCallback(static function (object $entity) use (&$persisted): void {
            $persisted[] = $entity;
        });

        $reservation = $this->service->reserve($this->sana, $this->line, 10);

        self::assertSame([$reservation], $persisted);
        self::assertSame(10, $reservation->getQuantity());
        self::assertEquals($reservation->getReservedAt()->modify('+48 hours'), $reservation->getExpiresAt());
    }

    public function testReservingAgainOverAnExpiredNotYetPurgedReservationRenewsTheSameRow(): void
    {
        $expiredReservation = new IndustryProjectLineReservation($this->line, $this->sana, 3, new \DateTimeImmutable('-50 hours'));
        $this->reservationRepository->method('findOneByLineAndParticipant')->willReturn(null);
        $this->reservationRepository->expects(self::once())
            ->method('findOneIncludingExpiredByLineAndParticipant')
            ->with($this->line, $this->sana)
            ->willReturn($expiredReservation);
        $this->reservationRepository->method('sumQuantityOfOthers')->willReturn(0);
        $this->entityManager->expects(self::never())->method('persist');
        $before = new \DateTimeImmutable();

        $reservation = $this->service->reserve($this->sana, $this->line, 10);

        self::assertSame($expiredReservation, $reservation);
        self::assertSame(10, $reservation->getQuantity());
        self::assertGreaterThanOrEqual($before, $reservation->getReservedAt());
        self::assertEquals($reservation->getReservedAt()->modify('+48 hours'), $reservation->getExpiresAt());
    }

    public function testReservingAgainAfterTenHoursKeepsOneReservationWithTheNewQuantityAndExpiry(): void
    {
        $firstReservation = new IndustryProjectLineReservation($this->line, $this->sana, 4, new \DateTimeImmutable('-10 hours'));
        $this->reservationRepository->method('findOneByLineAndParticipant')->willReturn($firstReservation);
        $this->reservationRepository->method('findOneIncludingExpiredByLineAndParticipant')->willReturn($firstReservation);
        $this->reservationRepository->method('sumQuantityOfOthers')->willReturn(0);
        $this->entityManager->expects(self::never())->method('persist');
        $before = new \DateTimeImmutable();

        $reservation = $this->service->reserve($this->sana, $this->line, 6);

        self::assertSame($firstReservation, $reservation);
        self::assertSame(6, $reservation->getQuantity());
        self::assertGreaterThanOrEqual($before, $reservation->getReservedAt());
        self::assertEquals($reservation->getReservedAt()->modify('+48 hours'), $reservation->getExpiresAt());
    }

    public function testReservationOfAnotherParticipantOverAnExpiredOneIsAcceptedWithTheWholeLineFree(): void
    {
        $this->reservationRepository->method('findOneIncludingExpiredByLineAndParticipant')->willReturn(null);
        $this->reservationRepository->expects(self::once())->method('sumQuantityOfOthers')->with($this->line, $this->maya)->willReturn(0);
        $this->entityManager->expects(self::once())->method('persist');

        $reservation = $this->service->reserve($this->maya, $this->line, null);

        self::assertSame(self::FEROX_REQUIRED_QUANTITY, $reservation->getQuantity());
        self::assertSame($this->maya, $reservation->getParticipant());
    }

    private function acceptedParticipantOf(IndustryProject $project): IndustryProjectParticipant
    {
        $participant = (new IndustryProjectParticipant())
            ->setUser(new User())
            ->setRole(ParticipantRole::Member)
            ->setStatus(ParticipantStatus::Accepted);
        $project->addMember($participant);

        return $participant;
    }
}
