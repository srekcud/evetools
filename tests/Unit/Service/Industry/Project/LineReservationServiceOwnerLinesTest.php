<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectLineReservation;
use App\Entity\IndustryProjectParticipant;
use App\Entity\User;
use App\Repository\IndustryProjectLineReservationRepository;
use App\Service\Industry\Project\LineReservationService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

#[CoversClass(LineReservationService::class)]
#[Group('issue-1357')]
final class LineReservationServiceOwnerLinesTest extends TestCase
{
    private const int FEROX_TYPE_ID = 16_227;
    private const int TRITANIUM_TYPE_ID = 34;
    private const int MORPHITE_TYPE_ID = 11_399;
    private const int FEROX_REQUIRED_QUANTITY = 10;
    private const int FEROX_FULFILLED_QUANTITY = 4;
    private const int TRITANIUM_REQUIRED_QUANTITY = 5_000;
    private const int MORPHITE_REQUIRED_QUANTITY = 300;

    private IndustryProject $project;
    private IndustryProjectParticipant $owner;
    private IndustryProjectLineReservationRepository&Stub $reservationRepository;
    private LineReservationService $service;

    /** @var list<IndustryProjectLineReservation> */
    private array $persisted = [];

    protected function setUp(): void
    {
        $this->project = (new IndustryProject())->setOwner(new User());
        $this->project->enrolOwner();
        $this->owner = $this->project->getOwnerParticipant();

        $this->reservationRepository = $this->createStub(IndustryProjectLineReservationRepository::class);
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('persist')->willReturnCallback(function (object $entity): void {
            if ($entity instanceof IndustryProjectLineReservation) {
                $this->persisted[] = $entity;
            }
        });
        $this->service = new LineReservationService($this->reservationRepository, $entityManager);
    }

    public function testEveryUnfulfilledLineIsReservedForTheOwnerForItsRemainingQuantity(): void
    {
        $ferox = $this->addLine(self::FEROX_TYPE_ID, self::FEROX_REQUIRED_QUANTITY, self::FEROX_FULFILLED_QUANTITY);
        $tritanium = $this->addLine(self::TRITANIUM_TYPE_ID, self::TRITANIUM_REQUIRED_QUANTITY);

        $this->service->reserveAllLinesForOwner($this->project);

        self::assertSame(
            [
                [$ferox, $this->owner, self::FEROX_REQUIRED_QUANTITY - self::FEROX_FULFILLED_QUANTITY],
                [$tritanium, $this->owner, self::TRITANIUM_REQUIRED_QUANTITY],
            ],
            array_map(
                static fn (IndustryProjectLineReservation $reservation): array => [$reservation->getBomLine(), $reservation->getParticipant(), $reservation->getQuantity()],
                $this->persisted,
            ),
        );
    }

    public function testAFulfilledLineAndALineCoveredByStockAreNotReserved(): void
    {
        $this->addLine(self::FEROX_TYPE_ID, self::FEROX_REQUIRED_QUANTITY, self::FEROX_REQUIRED_QUANTITY);
        $this->project->addBomItem((new IndustryProjectBomLine())
            ->setTypeId(self::MORPHITE_TYPE_ID)
            ->setTypeName('Morphite')
            ->setRequiredQuantity(self::MORPHITE_REQUIRED_QUANTITY)
            ->setIsJob(true)
            ->setRuns(0));
        $reservableLine = $this->addLine(self::TRITANIUM_TYPE_ID, self::TRITANIUM_REQUIRED_QUANTITY);

        $this->service->reserveAllLinesForOwner($this->project);

        self::assertSame([$reservableLine], array_map(
            static fn (IndustryProjectLineReservation $reservation): IndustryProjectBomLine => $reservation->getBomLine(),
            $this->persisted,
        ));
    }

    public function testAnExistingReservationOfTheOwnerIsRaisedToTheRemainingQuantityAndRenewedInsteadOfDuplicated(): void
    {
        $line = $this->addLine(self::TRITANIUM_TYPE_ID, self::TRITANIUM_REQUIRED_QUANTITY);
        $existing = new IndustryProjectLineReservation($line, $this->owner, 100, new \DateTimeImmutable('-50 hours'));
        $this->reservationRepository->method('findOneIncludingExpiredByLineAndParticipant')->willReturn($existing);
        $before = new \DateTimeImmutable();

        $this->service->reserveAllLinesForOwner($this->project);

        self::assertSame([], $this->persisted);
        self::assertSame(self::TRITANIUM_REQUIRED_QUANTITY, $existing->getQuantity());
        self::assertGreaterThanOrEqual($before->modify('+47 hours'), $existing->getExpiresAt());
    }

    private function addLine(int $typeId, int $requiredQuantity, int $fulfilledQuantity = 0): IndustryProjectBomLine
    {
        $line = (new IndustryProjectBomLine())
            ->setTypeId($typeId)
            ->setTypeName(sprintf('Type %d', $typeId))
            ->setRequiredQuantity($requiredQuantity)
            ->setFulfilledQuantity($fulfilledQuantity);
        $this->project->addBomItem($line);

        return $line;
    }
}
