<?php

declare(strict_types=1);

namespace App\State\Provider\Notification;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Notification\NotificationPreferencesResource;
use App\Entity\User;
use App\Repository\UserNotificationPreferenceRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<NotificationPreferencesResource>
 */
class PreferencesProvider implements ProviderInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly UserNotificationPreferenceRepository $preferenceRepository,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): NotificationPreferencesResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $preferences = $this->preferenceRepository->getOrCreateAll($user);

        $resource = new NotificationPreferencesResource();
        $resource->preferences = array_values(array_map(fn($p) => [
            'category' => $p->getCategory(),
            'enabled' => $p->isEnabled(),
            'thresholdMinutes' => $p->getThresholdMinutes(),
            'pushEnabled' => $p->isPushEnabled(),
        ], $preferences));

        return $resource;
    }
}
