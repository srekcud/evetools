<?php

declare(strict_types=1);

namespace App\State\Processor\Assets;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Assets\SyncStatusResource;
use App\Entity\User;
use App\Message\SyncCharacterAssets;
use App\Message\WarmupStructureOwnersMessage;
use App\Repository\CharacterRepository;
use App\Service\Sync\AssetsSyncService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

/**
 * @implements ProcessorInterface<mixed, SyncStatusResource>
 */
class RefreshCharacterAssetsProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly CharacterRepository $characterRepository,
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

        $uuid = Uuid::fromString($uriVariables['characterId']);
        $character = $this->characterRepository->find($uuid);

        if ($character === null || $character->getUser() !== $user) {
            throw new NotFoundHttpException('Character not found');
        }

        if (!$this->assetsSyncService->canSync($character)) {
            throw new AccessDeniedHttpException('Cannot sync - invalid token or auth');
        }

        $request = $this->requestStack->getCurrentRequest();
        $async = $request?->query->get('async', 'true') !== 'false';

        $result = new SyncStatusResource();

        if ($async) {
            $this->messageBus->dispatch(new SyncCharacterAssets($uriVariables['characterId']));

            $result->status = 'pending';
            $result->message = 'Asset sync started. Refresh the page in a few seconds.';

            return $result;
        }

        try {
            $this->assetsSyncService->syncCharacterAssets($character);

            $this->messageBus->dispatch(
                new WarmupStructureOwnersMessage($user->getId()?->toRfc4122() ?? '')
            );

            $result->status = 'completed';
            $result->message = 'Assets synced successfully';
        } catch (\Throwable $e) {
            $this->logger->error('Failed to sync character assets', [
                'characterId' => $uriVariables['characterId'],
                'error' => $e->getMessage(),
            ]);

            $result->status = 'error';
            $result->error = 'Failed to sync assets';
        }

        return $result;
    }
}
