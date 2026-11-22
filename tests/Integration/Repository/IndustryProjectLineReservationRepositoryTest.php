<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectLineReservation;
use App\Entity\IndustryProjectParticipant;
use App\Enum\ParticipantRole;
use App\Enum\ParticipantStatus;
use App\Repository\IndustryProjectLineReservationRepository;
use App\Service\Industry\Project\LineReservationService;
use App\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('issue-1301')]
final class IndustryProjectLineReservationRepositoryTest extends IntegrationTestCase
{
    private const int FEROX_TYPE_ID = 16_227;
    private const int FEROX_REQUIRED_QUANTITY = 10;

    private IndustryProjectLineReservationRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = self::getContainer()->get(IndustryProjectLineReservationRepository::class);
    }

    public function testReservationMadeJustUnderFortyEightHoursAgoIsStillReadAndCounted(): void
    {
        [$project, $line, $sana, $maya] = $this->projectWithTwoParticipants();
        $reservation = $this->reserveAt($line, $sana, 6, '-47 hours -59 minutes');
        $this->flushAndClear();
        [$project, $line, $sana, $maya] = $this->reloaded($project, $line, $sana, $maya);

        self::assertSame([$reservation->getId()->toRfc4122()], $this->idsOf($this->repository->findByProject($project)));
        self::assertSame([$reservation->getId()->toRfc4122()], $this->idsOf($this->repository->findByProjectAndParticipant($project, $sana)));
        self::assertSame(6, $this->repository->sumQuantityOfOthers($line, $maya));
        self::assertSame($reservation->getId()->toRfc4122(), $this->repository->findOneByLineAndParticipant($line, $sana)?->getId()->toRfc4122());
    }

    public function testReservationMadeFortyEightHoursAgoIsNoLongerReadNorCounted(): void
    {
        [$project, $line, $sana, $maya] = $this->projectWithTwoParticipants();
        $this->reserveAt($line, $sana, 6, '-48 hours');
        $this->flushAndClear();
        [$project, $line, $sana, $maya] = $this->reloaded($project, $line, $sana, $maya);

        self::assertSame([], $this->repository->findByProject($project));
        self::assertSame([], $this->repository->findByProjectAndParticipant($project, $sana));
        self::assertSame(0, $this->repository->sumQuantityOfOthers($line, $maya));
        self::assertNull($this->repository->findOneByLineAndParticipant($line, $sana));
    }

    public function testExpiredReservationStaysFindableForTheOwnerWhenExpiredOnesAreIncluded(): void
    {
        [$project, $line, $sana, $maya] = $this->projectWithTwoParticipants();
        $expired = $this->reserveAt($line, $sana, 6, '-50 hours');
        $this->flushAndClear();
        [, $line, $sana, $maya] = $this->reloaded($project, $line, $sana, $maya);

        self::assertSame($expired->getId()->toRfc4122(), $this->repository->findOneIncludingExpiredByLineAndParticipant($line, $sana)?->getId()->toRfc4122());
        self::assertNull($this->repository->findOneIncludingExpiredByLineAndParticipant($line, $maya));
    }

    public function testOnlyExpiredReservationsAreListedAsExpired(): void
    {
        [$project, $line, $sana, $maya] = $this->projectWithTwoParticipants();
        $otherLine = $this->addLine($project, 38, 'Nocxium', 120);
        $expiredOfSana = $this->reserveAt($line, $sana, 6, '-49 hours');
        $expiredOfMaya = $this->reserveAt($otherLine, $maya, 90, '-72 hours');
        $activeOfMaya = $this->reserveAt($line, $maya, 3, '-1 hour');
        $this->flushAndClear();

        $expiredIds = $this->idsOf($this->repository->findExpired());

        sort($expiredIds);
        $expectedIds = [$expiredOfSana->getId()->toRfc4122(), $expiredOfMaya->getId()->toRfc4122()];
        sort($expectedIds);
        self::assertSame($expectedIds, $expiredIds);
        self::assertNotContains($activeOfMaya->getId()->toRfc4122(), $expiredIds);
    }

    public function testAnotherParticipantMayReserveTheWholeLineOverAnExpiredNotYetPurgedReservation(): void
    {
        [$project, $line, $sana, $maya] = $this->projectWithTwoParticipants();
        $this->reserveAt($line, $sana, 10, '-50 hours');
        $this->flushAndClear();
        [, $line, , $maya] = $this->reloaded($project, $line, $sana, $maya);

        $reservation = self::getContainer()->get(LineReservationService::class)->reserve($maya, $line, 10);

        self::assertSame(10, $reservation->getQuantity());
        self::assertSame($maya->getId()->toRfc4122(), $reservation->getParticipant()->getId()->toRfc4122());
    }

    public function testReservingAgainOverAnExpiredNotYetPurgedReservationRenewsItWithoutASecondRow(): void
    {
        [$project, $line, $sana, $maya] = $this->projectWithTwoParticipants();
        $expired = $this->reserveAt($line, $sana, 3, '-50 hours');
        $expiredId = $expired->getId()->toRfc4122();
        $this->flushAndClear();
        [, $line, $sana] = $this->reloaded($project, $line, $sana, $maya);
        $before = new \DateTimeImmutable('@'.time());

        self::getContainer()->get(LineReservationService::class)->reserve($sana, $line, 8);

        $this->em->clear();
        $stored = $this->em->getRepository(IndustryProjectLineReservation::class)->findAll();
        self::assertCount(1, $stored);
        self::assertSame($expiredId, $stored[0]->getId()->toRfc4122());
        self::assertSame(8, $stored[0]->getQuantity());
        self::assertGreaterThanOrEqual($before, $stored[0]->getReservedAt());
        self::assertEquals($stored[0]->getReservedAt()->modify('+48 hours'), $stored[0]->getExpiresAt());
    }

    /** @return array{IndustryProject, IndustryProjectBomLine, IndustryProjectParticipant, IndustryProjectParticipant} */
    private function projectWithTwoParticipants(): array
    {
        $project = (new IndustryProject())->setOwner($this->createUser())->setName('Ferox run');
        $project->enrolOwner();
        $line = $this->addLine($project, self::FEROX_TYPE_ID, 'Ferox', self::FEROX_REQUIRED_QUANTITY);
        $sana = $this->acceptedParticipant($project);
        $maya = $this->acceptedParticipant($project);
        $this->em->persist($project);
        $this->em->flush();

        return [$project, $line, $sana, $maya];
    }

    private function addLine(IndustryProject $project, int $typeId, string $typeName, int $requiredQuantity): IndustryProjectBomLine
    {
        $line = (new IndustryProjectBomLine())
            ->setTypeId($typeId)
            ->setTypeName($typeName)
            ->setRequiredQuantity($requiredQuantity)
            ->setIsJob(false);
        $project->addBomItem($line);

        return $line;
    }

    private function acceptedParticipant(IndustryProject $project): IndustryProjectParticipant
    {
        $participant = (new IndustryProjectParticipant())
            ->setUser($this->createUser())
            ->setRole(ParticipantRole::Member)
            ->setStatus(ParticipantStatus::Accepted);
        $project->addMember($participant);

        return $participant;
    }

    private function reserveAt(IndustryProjectBomLine $line, IndustryProjectParticipant $participant, int $quantity, string $offsetFromNow): IndustryProjectLineReservation
    {
        $reservation = new IndustryProjectLineReservation($line, $participant, $quantity, (new \DateTimeImmutable())->modify($offsetFromNow));
        $this->em->persist($reservation);

        return $reservation;
    }

    /** @return array{IndustryProject, IndustryProjectBomLine, IndustryProjectParticipant, IndustryProjectParticipant} */
    private function reloaded(IndustryProject $project, IndustryProjectBomLine $line, IndustryProjectParticipant $sana, IndustryProjectParticipant $maya): array
    {
        return [
            $this->em->find(IndustryProject::class, $project->getId()),
            $this->em->find(IndustryProjectBomLine::class, $line->getId()),
            $this->em->find(IndustryProjectParticipant::class, $sana->getId()),
            $this->em->find(IndustryProjectParticipant::class, $maya->getId()),
        ];
    }

    /**
     * @param list<IndustryProjectLineReservation> $reservations
     *
     * @return list<string>
     */
    private function idsOf(array $reservations): array
    {
        return array_map(static fn (IndustryProjectLineReservation $reservation): string => $reservation->getId()->toRfc4122(), $reservations);
    }
}
