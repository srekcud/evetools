<?php

declare(strict_types=1);

namespace App\State\Provider\Admin;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Admin\QueueResource;
use App\Entity\User;
use App\Service\Admin\AdminService;
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
        private readonly AdminService $adminService,
        /** @var list<string> */
        private readonly array $adminCharacterNames,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): QueueResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $this->checkAdminAccess($user);

        $queueStatus = $this->adminService->getQueueStatus();

        $resource = new QueueResource();
        $resource->queues = $queueStatus['queues'] ?? [];

        return $resource;
    }

    private function checkAdminAccess(User $user): void
    {
        $mainChar = $user->getMainCharacter();
        if (!$mainChar) {
            throw new AccessDeniedHttpException('Forbidden');
        }

        $mainCharName = strtolower($mainChar->getName());
        $isAdmin = false;
        foreach ($this->adminCharacterNames as $adminName) {
            if (strtolower($adminName) === $mainCharName) {
                $isAdmin = true;
                break;
            }
        }

        if (!$isAdmin) {
            throw new AccessDeniedHttpException('Forbidden');
        }
    }
}
