<?php

declare(strict_types=1);

namespace App\State\Provider\Admin;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Admin\QueueResource;
use App\Entity\User;
use App\Security\AdminChecker;
use App\Service\Admin\MessengerQueueService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<QueueResource>
 */
class QueueStatusProvider implements ProviderInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly MessengerQueueService $queueService,
        private readonly AdminChecker $adminChecker,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): QueueResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $this->checkAdminAccess($user);

        $resource = new QueueResource();
        $resource->queues = [
            'async' => $this->queueService->getDepth('async'),
            'failed' => $this->queueService->getDepth('failed'),
            'on_demand' => $this->queueService->getDepth('on_demand'),
        ];

        return $resource;
    }

    private function checkAdminAccess(User $user): void
    {
        if (!$this->adminChecker->isAdmin($user)) {
            throw new AccessDeniedHttpException('Forbidden');
        }
    }
}
