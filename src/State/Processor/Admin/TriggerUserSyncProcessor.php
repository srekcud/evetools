<?php

declare(strict_types=1);

namespace App\State\Processor\Admin;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Admin\ActionResultResource;
use App\ApiResource\Input\Admin\TriggerUserSyncInput;
use App\Repository\UserRepository;
use App\Security\AdminAccessGuard;
use App\Service\Admin\UserTriggerableSyncs;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

/**
 * @implements ProcessorInterface<TriggerUserSyncInput, ActionResultResource>
 */
class TriggerUserSyncProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly AdminAccessGuard $accessGuard,
        private readonly UserRepository $users,
        private readonly MessageBusInterface $messageBus,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ActionResultResource
    {
        $admin = $this->accessGuard->requireAdmin();

        $userId = (string) ($uriVariables['userId'] ?? '');
        if (!Uuid::isValid($userId) || $this->users->find(Uuid::fromString($userId)) === null) {
            throw new NotFoundHttpException('User not found');
        }

        $resource = new ActionResultResource();
        $type = $data instanceof TriggerUserSyncInput ? $data->type : '';
        if (!UserTriggerableSyncs::isTriggerable($type)) {
            $resource->success = false;
            $resource->message = 'Unknown sync type';

            return $resource;
        }

        $this->messageBus->dispatch(UserTriggerableSyncs::messageFor($type, $userId));
        $this->logger->info('Admin triggered a user sync', [
            'adminId' => $admin->getId()?->toRfc4122(),
            'userId' => $userId,
            'type' => $type,
        ]);

        $resource->success = true;
        $resource->message = sprintf('Sync triggered: %s', $type);

        return $resource;
    }
}
