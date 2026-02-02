<?php

declare(strict_types=1);

namespace App\State\Processor\Ansiblex;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Ansiblex\AnsiblexSyncResultResource;
use App\Entity\User;
use App\Message\SyncAnsiblexGates;
use App\Service\Sync\AnsiblexSyncService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * @implements ProcessorInterface<mixed, AnsiblexSyncResultResource>
 */
class RefreshAnsiblexProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly AnsiblexSyncService $ansiblexSyncService,
        private readonly MessageBusInterface $messageBus,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): AnsiblexSyncResultResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $mainCharacter = $user->getMainCharacter();
        if ($mainCharacter === null) {
            throw new AccessDeniedHttpException('No main character set');
        }

        if (!$this->ansiblexSyncService->canSync($mainCharacter)) {
            $result = new AnsiblexSyncResultResource();
            $result->status = 'error';
            $result->error = 'Cannot sync Ansiblex gates';
            $result->reason = 'Missing required scope (esi-corporations.read_structures.v1) or invalid auth';

            return $result;
        }

        $request = $this->requestStack->getCurrentRequest();
        $async = $request?->query->getBoolean('async', true) ?? true;

        $result = new AnsiblexSyncResultResource();

        if ($async) {
            $this->messageBus->dispatch(new SyncAnsiblexGates($mainCharacter->getId()->toRfc4122()));

            $result->status = 'queued';
            $result->message = 'Ansiblex sync has been queued';

            return $result;
        }

        try {
            $stats = $this->ansiblexSyncService->syncFromCharacter($mainCharacter);

            $result->status = 'completed';
            $result->stats = $stats;

            if ($stats['added'] === 0 && $stats['updated'] === 0 && $stats['deactivated'] === 0) {
                $result->warning = 'No structures found. Character may lack Director/Station_Manager role in corporation.';
            }
        } catch (\Exception $e) {
            $result->status = 'error';
            $result->error = 'Sync failed';
            $result->message = $e->getMessage();
        }

        return $result;
    }
}
