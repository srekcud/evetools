<?php

declare(strict_types=1);

namespace App\State\Provider\Admin;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Admin\AdminStatusResource;
use App\Entity\User;
use App\Security\AdminChecker;
use App\Service\Admin\AdminService;
use App\Service\Admin\AdminStatusEvaluator;
use App\Service\Admin\MessengerQueueService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<AdminStatusResource>
 */
class AdminStatusProvider implements ProviderInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly AdminService $adminService,
        private readonly MessengerQueueService $queueService,
        private readonly AdminStatusEvaluator $evaluator,
        private readonly AdminChecker $adminChecker,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): AdminStatusResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        if (!$this->adminChecker->isAdmin($user)) {
            throw new AccessDeniedHttpException('Forbidden');
        }

        $stats = $this->adminService->getStats();

        $status = $this->evaluator->evaluate(
            $stats['schedulerHealth'],
            $stats['users']['invalid'],
            $this->queueService->getDepth('async'),
            $this->queueService->getDepth('failed'),
        );

        $resource = new AdminStatusResource();
        $resource->level = $status->level;
        $resource->rules = $status->rules;

        return $resource;
    }
}
