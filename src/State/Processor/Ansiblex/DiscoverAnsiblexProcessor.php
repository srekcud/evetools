<?php

declare(strict_types=1);

namespace App\State\Processor\Ansiblex;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Ansiblex\AnsiblexSyncResultResource;
use App\Entity\User;
use App\Service\Sync\AnsiblexSyncService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProcessorInterface<mixed, AnsiblexSyncResultResource>
 */
class DiscoverAnsiblexProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly AnsiblexSyncService $ansiblexSyncService,
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

        if (!$this->ansiblexSyncService->canSyncViaSearch($mainCharacter)) {
            $result = new AnsiblexSyncResultResource();
            $result->status = 'error';
            $result->error = 'Cannot discover Ansiblex gates';
            $result->reason = 'Missing required scopes (esi-search.search_structures.v1 and esi-universe.read_structures.v1)';

            return $result;
        }

        $result = new AnsiblexSyncResultResource();

        try {
            $stats = $this->ansiblexSyncService->syncViaSearch($mainCharacter);

            $result->status = 'completed';
            $result->stats = $stats;
            $result->message = sprintf(
                'Discovered %d structures, added %d new gates, updated %d existing gates',
                $stats['discovered'],
                $stats['added'],
                $stats['updated']
            );
        } catch (\Exception $e) {
            $result->status = 'error';
            $result->error = 'Discovery failed';
            $result->message = $e->getMessage();
        }

        return $result;
    }
}
