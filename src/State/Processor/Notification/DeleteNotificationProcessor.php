<?php

declare(strict_types=1);

namespace App\State\Processor\Notification;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Repository\NotificationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

/** @implements ProcessorInterface<mixed, void> */
class DeleteNotificationProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly NotificationRepository $notificationRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): void
    {
        $id = $uriVariables['id'] ?? null;
        if ($id === null) {
            throw new NotFoundHttpException('Notification not found');
        }

        $notification = $this->notificationRepository->find(Uuid::fromString((string) $id));
        if ($notification === null) {
            throw new NotFoundHttpException('Notification not found');
        }

        $this->entityManager->remove($notification);
        $this->entityManager->flush();
    }
}
