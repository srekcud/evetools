<?php

declare(strict_types=1);

namespace App\State\Provider\Notification;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Notification\NotificationResource;
use App\Entity\User;
use App\Repository\NotificationRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<NotificationResource>
 */
class NotificationCollectionProvider implements ProviderInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly NotificationRepository $notificationRepository,
        private readonly RequestStack $requestStack,
    ) {
    }

    /**
     * @return NotificationResource[]
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $request = $this->requestStack->getCurrentRequest();
        $page = max(1, (int) ($request?->query->get('page', '1') ?? '1'));
        $category = $request?->query->get('category');
        $isReadParam = $request?->query->get('isRead');

        $isRead = null;
        if ($isReadParam !== null) {
            $isRead = filter_var($isReadParam, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        }

        $notifications = $this->notificationRepository->findPaginated($user, $page, $category, $isRead);

        return array_map(fn($n) => NotificationResourceMapper::toResource($n), $notifications);
    }
}
