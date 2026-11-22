<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project\Action;

use App\Entity\Character;
use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectLineReservation;
use App\Entity\IndustryProjectParticipant;
use App\Entity\User;
use App\Repository\IndustryProjectLineReservationRepository;

trait BuildsLineReservations
{
    private static int $nextReserverEveCharacterId = 2_113_360_001;

    private function reserverNamed(string $name): User
    {
        $character = (new Character())->setEveCharacterId(self::$nextReserverEveCharacterId++)->setName($name);
        $reserver = new User();
        $reserver->addCharacter($character);
        $reserver->setMainCharacter($character);

        return $reserver;
    }

    private function reservationOf(IndustryProjectBomLine $line, ?User $reserver, int $quantity): IndustryProjectLineReservation
    {
        $participant = new IndustryProjectParticipant();
        if (null !== $reserver) {
            $participant->setUser($reserver);
        }

        return new IndustryProjectLineReservation($line, $participant, $quantity);
    }

    private function reservationsHolding(IndustryProjectLineReservation ...$reservations): IndustryProjectLineReservationRepository
    {
        $repository = $this->createStub(IndustryProjectLineReservationRepository::class);
        $repository->method('findByProject')->willReturn($reservations);

        return $repository;
    }

    private function noReservations(): IndustryProjectLineReservationRepository
    {
        return $this->createStub(IndustryProjectLineReservationRepository::class);
    }
}
