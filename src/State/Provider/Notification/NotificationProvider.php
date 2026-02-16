<?php

declare(strict_types=1);

namespace App\State\Provider\Notification;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Notification\NotificationResource;
use App\Entity\User;
use App\Repository\NotificationRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * @implements ProviderInterface<NotificationResource>
 */
class NotificationProvider implements ProviderInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly NotificationRepository $notificationRepository,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): NotificationResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $id = $uriVariables['id'] ?? null;
        if ($id === null) {
            throw new NotFoundHttpException('Notification not found');
        }

        $notification = $this->notificationRepository->find(Uuid::fromString($id));
        if ($notification === null) {
            throw new NotFoundHttpException('Notification not found');
        }

        if (!$notification->isOwnedBy($user)) {
            throw new AccessDeniedHttpException('Access denied');
        }

        return NotificationResourceMapper::toResource($notification);
    }
}
