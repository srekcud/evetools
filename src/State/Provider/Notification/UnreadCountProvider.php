<?php

declare(strict_types=1);

namespace App\State\Provider\Notification;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Notification\UnreadCountResource;
use App\Entity\User;
use App\Repository\NotificationRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<UnreadCountResource>
 */
class UnreadCountProvider implements ProviderInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly NotificationRepository $notificationRepository,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): UnreadCountResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $resource = new UnreadCountResource();
        $resource->count = $this->notificationRepository->countUnread($user);

        return $resource;
    }
}
