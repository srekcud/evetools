<?php

declare(strict_types=1);

namespace App\Service\Planetary;

use App\Entity\Notification;
use App\Entity\User;
use App\Entity\UserNotificationPreference;
use App\Repository\UserNotificationPreferenceRepository;

class PlanetaryExpiryThresholdResolver
{
    public function __construct(
        private readonly UserNotificationPreferenceRepository $preferenceRepository,
    ) {
    }

    public function minutesFor(User $user): int
    {
        $preference = $this->preferenceRepository->findByUserAndCategory($user, Notification::CATEGORY_PLANETARY);

        return $preference?->getThresholdMinutes() ?? UserNotificationPreference::DEFAULT_PLANETARY_THRESHOLD_MINUTES;
    }
}
