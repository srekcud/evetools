<?php

declare(strict_types=1);

namespace App\State\Processor\Admin;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Admin\ActionResultResource;
use App\Entity\User;
use App\Security\AdminChecker;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProcessorInterface<mixed, ActionResultResource>
 */
class PurgeFailedProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly Connection $connection,
        private readonly AdminChecker $adminChecker,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ActionResultResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $this->checkAdminAccess($user);

        $resource = new ActionResultResource();

        try {
            $deleted = $this->connection->executeStatement(
                "DELETE FROM messenger_messages WHERE queue_name = 'failed'"
            );

            $resource->success = true;
            $resource->message = "Purged {$deleted} failed messages";
            $resource->deleted = (int) $deleted;
        } catch (\Throwable $e) {
            $resource->success = false;
            $resource->message = 'Error: ' . $e->getMessage();
        }

        return $resource;
    }

    private function checkAdminAccess(User $user): void
    {
        if (!$this->adminChecker->isAdmin($user)) {
            throw new AccessDeniedHttpException('Forbidden');
        }
    }
}
