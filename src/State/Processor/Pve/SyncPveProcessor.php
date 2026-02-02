<?php

declare(strict_types=1);

namespace App\State\Processor\Pve;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Input\Pve\SyncInput;
use App\ApiResource\Pve\PveSyncResource;
use App\Entity\User;
use App\Repository\UserPveSettingsRepository;
use App\Service\Sync\PveSyncService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProcessorInterface<SyncInput, PveSyncResource>
 */
class SyncPveProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly PveSyncService $pveSyncService,
        private readonly UserPveSettingsRepository $settingsRepository,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): PveSyncResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        if (!$this->pveSyncService->canSync($user)) {
            throw new BadRequestHttpException('No valid ESI tokens available for sync');
        }

        $results = $this->pveSyncService->syncAll($user);
        $settings = $this->settingsRepository->findByUser($user);

        $resource = new PveSyncResource();
        $resource->status = 'success';
        $resource->message = sprintf(
            'Synced %d bounties, %d loot sales, %d loot contracts, %d expenses',
            $results['bounties'],
            $results['lootSales'],
            $results['lootContracts'],
            $results['expenses']
        );
        $resource->imported = $results;
        $resource->lastSyncAt = $settings?->getLastSyncAt()?->format('c');
        $resource->errors = $results['errors'];

        return $resource;
    }
}
