<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectLineReservation;
use App\Entity\IndustryProjectParticipant;
use App\Repository\IndustryProjectLineReservationRepository;
use Doctrine\ORM\EntityManagerInterface;

class LineReservationService
{
    public function __construct(
        private readonly IndustryProjectLineReservationRepository $reservationRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @throws \DomainException if the line cannot be reserved or the quantity is out of the free quantity
     */
    public function reserve(IndustryProjectParticipant $participant, IndustryProjectBomLine $line, ?int $quantity): IndustryProjectLineReservation
    {
        if ($line->getProject()->isCompleted()) {
            throw new \DomainException('Cannot reserve a line of a completed project');
        }

        if ($line->isFulfilled()) {
            throw new \DomainException('The line is already complete');
        }

        $freeQuantity = $line->getRemainingQuantity() - $this->reservationRepository->sumQuantityOfOthers($line, $participant);
        if ($freeQuantity < 1) {
            throw new \DomainException('No free quantity left on the line');
        }

        $reservedQuantity = $quantity ?? $freeQuantity;
        if ($reservedQuantity < 1 || $reservedQuantity > $freeQuantity) {
            throw new \DomainException(sprintf('The quantity to reserve must be between 1 and %d', $freeQuantity));
        }

        $reservation = $this->reservationRepository->findOneByLineAndParticipant($line, $participant);
        if ($reservation === null) {
            $reservation = new IndustryProjectLineReservation($line, $participant, $reservedQuantity);
            $this->entityManager->persist($reservation);
        } else {
            $reservation->setQuantity($reservedQuantity);
        }

        $this->entityManager->flush();

        return $reservation;
    }

    public function release(IndustryProjectLineReservation $reservation): void
    {
        $this->entityManager->remove($reservation);
        $this->entityManager->flush();
    }

    public function releaseAllOf(IndustryProjectParticipant $participant): void
    {
        $this->reservationRepository->deleteByParticipant($participant);
    }

    public function releaseAllOfProject(IndustryProject $project): void
    {
        $this->reservationRepository->deleteByProject($project);
    }

    public function settleApproval(IndustryProjectBomLine $line, IndustryProjectParticipant $participant, int $quantity): void
    {
        if ($line->isFulfilled()) {
            $this->reservationRepository->deleteByLine($line);

            return;
        }

        $reservation = $this->reservationRepository->findOneByLineAndParticipant($line, $participant);
        if ($reservation === null) {
            return;
        }

        $remainingReservedQuantity = $reservation->getQuantity() - $quantity;
        if ($remainingReservedQuantity < 1) {
            $this->entityManager->remove($reservation);

            return;
        }

        $reservation->setQuantity($remainingReservedQuantity);
    }
}
