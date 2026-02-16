<?php

declare(strict_types=1);

namespace App\State\Processor\Notification;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Notification\NotificationResource;
use App\Entity\User;
use App\Repository\NotificationRepository;
use App\State\Provider\Notification\NotificationResourceMapper;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * @implements ProcessorInterface<NotificationResource, NotificationResource>
 */
class MarkReadProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly NotificationRepository $notificationRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): NotificationResource
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

        $notification->setIsRead(true);
        $this->entityManager->flush();

        return NotificationResourceMapper::toResource($notification);
    }
}
