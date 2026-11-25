<?php

declare(strict_types=1);

namespace App\Service\Planetary;

use App\Entity\Character;
use App\Repository\Sde\InvTypeRepository;
use App\Repository\NotificationRepository;
use App\Repository\PlanetaryColonyRepository;
use App\Service\Notification\NotificationDispatcher;
use Symfony\Component\Clock\ClockInterface;

final readonly class PlanetaryAlertEvaluator
{
    public function __construct(
        private PlanetaryColonyRepository $colonyRepository,
        private PlanetaryInputNeedsCalculator $inputNeedsCalculator,
        private InvTypeRepository $invTypeRepository,
        private PlanetaryExpiryThresholdResolver $expiryThresholdResolver,
        private NotificationRepository $notificationRepository,
        private NotificationDispatcher $notificationDispatcher,
        private ClockInterface $clock,
    ) {
    }

    public function evaluate(Character $character): void
    {
    }
}
