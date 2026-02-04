<?php

declare(strict_types=1);

namespace App\State\Processor\Ledger;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Ledger\MiningSyncResource;
use App\Entity\User;
use App\Repository\UserLedgerSettingsRepository;
use App\Service\Sync\MiningSyncService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProcessorInterface<object, MiningSyncResource>
 */
class SyncMiningProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly MiningSyncService $miningSyncService,
        private readonly UserLedgerSettingsRepository $settingsRepository,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): MiningSyncResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $results = $this->miningSyncService->syncAll($user);
        $settings = $this->settingsRepository->findByUser($user);

        $resource = new MiningSyncResource();
        $resource->status = empty($results['errors']) ? 'success' : 'partial';
        $resource->message = sprintf(
            '%d entries imported, %d updated, %d prices refreshed',
            $results['imported'],
            $results['updated'],
            $results['pricesUpdated']
        );
        $resource->imported = [
            'entries' => $results['imported'],
            'updated' => $results['updated'],
            'pricesUpdated' => $results['pricesUpdated'],
        ];
        $resource->lastSyncAt = $settings?->getLastMiningSyncAt()?->format('c');
        $resource->errors = $results['errors'];

        return $resource;
    }
}
