<?php

declare(strict_types=1);

namespace App\State\Processor\Notification;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Input\Notification\PreferencesInput;
use App\ApiResource\Notification\NotificationPreferencesResource;
use App\Entity\Notification;
use App\Entity\User;
use App\Entity\UserNotificationPreference;
use App\Repository\UserNotificationPreferenceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProcessorInterface<PreferencesInput, NotificationPreferencesResource>
 */
class PreferencesProcessor implements ProcessorInterface
{
    private const VALID_CATEGORIES = [
        Notification::CATEGORY_PLANETARY,
        Notification::CATEGORY_INDUSTRY,
        Notification::CATEGORY_ESCALATION,
        Notification::CATEGORY_ESI,
        Notification::CATEGORY_PRICE,
    ];

    public function __construct(
        private readonly Security $security,
        private readonly UserNotificationPreferenceRepository $preferenceRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): NotificationPreferencesResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        assert($data instanceof PreferencesInput);

        foreach ($data->preferences as $prefData) {
            $category = $prefData['category'] ?? null;
            if (!is_string($category) || !in_array($category, self::VALID_CATEGORIES, true)) {
                continue;
            }

            $pref = $this->preferenceRepository->findByUserAndCategory($user, $category);

            if ($pref === null) {
                $pref = new UserNotificationPreference();
                $pref->setUser($user);
                $pref->setCategory($category);
                $this->entityManager->persist($pref);
            }

            if (isset($prefData['enabled'])) {
                $pref->setEnabled((bool) $prefData['enabled']);
            }

            if (array_key_exists('thresholdMinutes', $prefData)) {
                $threshold = $prefData['thresholdMinutes'];
                $pref->setThresholdMinutes($threshold !== null ? (int) $threshold : null);
            }

            if (isset($prefData['pushEnabled'])) {
                $pref->setPushEnabled((bool) $prefData['pushEnabled']);
            }
        }

        $this->entityManager->flush();

        // Return updated preferences
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
