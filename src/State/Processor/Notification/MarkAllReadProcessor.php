<?php

declare(strict_types=1);

namespace App\State\Processor\Notification;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Input\EmptyInput;
use App\ApiResource\Notification\NotificationResource;
use App\Entity\User;
use App\Repository\NotificationRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProcessorInterface<EmptyInput, NotificationResource>
 */
class MarkAllReadProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly NotificationRepository $notificationRepository,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): NotificationResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $updated = $this->notificationRepository->markAllAsRead($user);

        $resource = new NotificationResource();
        $resource->id = 'read-all';
        $resource->category = '';
        $resource->level = 'info';
        $resource->title = 'All read';
        $resource->message = sprintf('%d notifications marked as read', $updated);
        $resource->isRead = true;
        $resource->createdAt = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);

        return $resource;
    }
}
