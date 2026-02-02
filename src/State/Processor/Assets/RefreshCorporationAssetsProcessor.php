<?php

declare(strict_types=1);

namespace App\State\Processor\Assets;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Assets\SyncStatusResource;
use App\Entity\User;
use App\Message\SyncCorporationAssets;
use App\Service\Sync\AssetsSyncService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * @implements ProcessorInterface<mixed, SyncStatusResource>
 */
class RefreshCorporationAssetsProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly AssetsSyncService $assetsSyncService,
        private readonly MessageBusInterface $messageBus,
        private readonly RequestStack $requestStack,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): SyncStatusResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $mainCharacter = $user->getMainCharacter();
        if ($mainCharacter === null) {
            throw new AccessDeniedHttpException('No main character set');
        }

        $corporationId = $mainCharacter->getCorporationId();

        if (!$this->assetsSyncService->canSyncCorporationAssets($corporationId)) {
            $result = new SyncStatusResource();
            $result->status = 'error';
            $result->error = 'No character with corporation assets access. A director with esi-assets.read_corporation_assets.v1 scope must be linked.';
            $result->hasAccess = false;

            return $result;
        }

        $request = $this->requestStack->getCurrentRequest();
        $async = $request?->query->get('async', 'true') !== 'false';

        $result = new SyncStatusResource();

        if ($async) {
            $this->messageBus->dispatch(new SyncCorporationAssets(
                $corporationId,
                $mainCharacter->getId()->toRfc4122()
            ));

            $result->status = 'pending';
            $result->message = 'Corporation asset sync started. Refresh the page in a few seconds.';

            return $result;
        }

        try {
            $success = $this->assetsSyncService->syncCorporationAssetsForCorp($corporationId);

            if (!$success) {
                $result->status = 'error';
                $result->error = 'Failed to sync corporation assets - Director role may be required in-game';

                return $result;
            }

            $result->status = 'completed';
            $result->message = 'Corporation assets synced successfully';
        } catch (\Throwable $e) {
            $this->logger->error('Failed to sync corporation assets', [
                'corporationId' => $corporationId,
                'error' => $e->getMessage(),
            ]);

            $result->status = 'error';
            $result->error = 'Failed to sync corporation assets: ' . $e->getMessage();
        }

        return $result;
    }
}
