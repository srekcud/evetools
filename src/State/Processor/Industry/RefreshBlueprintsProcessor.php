<?php

declare(strict_types=1);

namespace App\State\Processor\Industry;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Assets\SyncStatusResource;
use App\Entity\User;
use App\Message\SyncUserBlueprints;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * @implements ProcessorInterface<mixed, SyncStatusResource>
 */
final readonly class RefreshBlueprintsProcessor implements ProcessorInterface
{
    public function __construct(
        private Security $security,
        private MessageBusInterface $messageBus,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): SyncStatusResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $userId = $user->getId()?->toRfc4122() ?? throw new \LogicException('Cannot refresh the blueprints of a user without id');

        $this->messageBus->dispatch(new SyncUserBlueprints($userId));

        $result = new SyncStatusResource();
        $result->status = 'pending';
        $result->message = 'Blueprints sync started.';

        return $result;
    }
}
