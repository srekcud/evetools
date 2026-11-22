<?php

declare(strict_types=1);

namespace App\Service\Industry\Project\Action;

use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectLineReservation;
use App\Entity\User;
use App\Service\Industry\Project\PilotName;

final readonly class ReservationsSeenByMember
{
    /**
     * @param array<string, list<array{characterName: ?string, quantity: int}>> $othersByLineId
     * @param array<string, true> $lineIdsHeldByViewer
     */
    private function __construct(
        private array $othersByLineId,
        private array $lineIdsHeldByViewer,
    ) {
    }

    /**
     * @param IndustryProjectLineReservation[] $reservations
     */
    public static function of(array $reservations, User $viewer): self
    {
        $othersByLineId = [];
        $lineIdsHeldByViewer = [];
        foreach ($reservations as $reservation) {
            $lineId = $reservation->getBomLine()->getId()->toRfc4122();
            $holder = $reservation->getParticipant()->getUser();
            if ($holder === $viewer) {
                $lineIdsHeldByViewer[$lineId] = true;

                continue;
            }

            $othersByLineId[$lineId][] = ['characterName' => PilotName::of($holder), 'quantity' => $reservation->getQuantity()];
        }

        return new self($othersByLineId, $lineIdsHeldByViewer);
    }

    /**
     * @return list<array{characterName: ?string, quantity: int}>
     */
    public function heldByOthersOn(IndustryProjectBomLine $line): array
    {
        return $this->othersByLineId[$line->getId()->toRfc4122()] ?? [];
    }

    public function quantityHeldByOthersOn(IndustryProjectBomLine $line): int
    {
        return array_sum(array_column($this->heldByOthersOn($line), 'quantity'));
    }

    public function isHeldByViewerOn(IndustryProjectBomLine $line): bool
    {
        return isset($this->lineIdsHeldByViewer[$line->getId()->toRfc4122()]);
    }
}
