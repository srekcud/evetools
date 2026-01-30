<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Message\SyncCharacterAssets;
use App\Message\SyncCorporationAssets;
use App\Repository\CachedAssetRepository;
use App\Repository\CharacterRepository;
use App\Service\Sync\AssetsSyncService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

#[Route('/api/me')]
class AssetsController extends AbstractController
{
    public function __construct(
        private readonly Security $security,
        private readonly CharacterRepository $characterRepository,
        private readonly CachedAssetRepository $cachedAssetRepository,
        private readonly AssetsSyncService $assetsSyncService,
        private readonly MessageBusInterface $messageBus,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/characters/{characterId}/assets', name: 'api_character_assets', methods: ['GET'])]
    public function getCharacterAssets(string $characterId, Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->security->getUser();

        $uuid = Uuid::fromString($characterId);
        $character = $this->characterRepository->find($uuid);

        if ($character === null || $character->getUser() !== $user) {
            return new JsonResponse(['error' => 'Character not found'], Response::HTTP_NOT_FOUND);
        }

        $locationId = $request->query->get('locationId');

        if ($locationId !== null) {
            $assets = $this->cachedAssetRepository->findByCharacterAndLocation($character, (int) $locationId);
        } else {
            $assets = $this->cachedAssetRepository->findByCharacter($character);
        }

        return $this->formatAssetsResponse($assets);
    }

    #[Route('/characters/{characterId}/assets/refresh', name: 'api_character_assets_refresh', methods: ['POST'])]
    public function refreshCharacterAssets(string $characterId, Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->security->getUser();

        $uuid = Uuid::fromString($characterId);
        $character = $this->characterRepository->find($uuid);

        if ($character === null || $character->getUser() !== $user) {
            return new JsonResponse(['error' => 'Character not found'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->assetsSyncService->canSync($character)) {
            return new JsonResponse([
                'error' => 'Cannot sync - invalid token or auth',
            ], Response::HTTP_FORBIDDEN);
        }

        // Use async=false query param to force synchronous processing (for testing)
        $async = $request->query->get('async', 'true') !== 'false';

        if ($async) {
            // Dispatch async message - returns immediately
            $this->messageBus->dispatch(new SyncCharacterAssets($characterId));

            return new JsonResponse([
                'status' => 'pending',
                'message' => 'Asset sync started. Refresh the page in a few seconds.',
            ]);
        }

        // Synchronous processing (fallback)
        try {
            $this->assetsSyncService->syncCharacterAssets($character);

            return new JsonResponse([
                'status' => 'completed',
                'message' => 'Assets synced successfully',
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to sync character assets', [
                'characterId' => $characterId,
                'error' => $e->getMessage(),
            ]);

            return new JsonResponse([
                'error' => 'Failed to sync assets',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/corporation/assets', name: 'api_corporation_assets', methods: ['GET'])]
    public function getCorporationAssets(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->security->getUser();

        $mainCharacter = $user->getMainCharacter();
        if ($mainCharacter === null) {
            return new JsonResponse(['error' => 'No main character set'], Response::HTTP_FORBIDDEN);
        }

        $corporationId = $mainCharacter->getCorporationId();
        $divisionName = $request->query->get('divisionName');

        $assets = $divisionName !== null
            ? $this->cachedAssetRepository->findByCorporationAndDivision($corporationId, $divisionName)
            : $this->cachedAssetRepository->findByCorporationId($corporationId);

        return $this->formatAssetsResponse($assets, includeDivision: true);
    }

    #[Route('/corporation/assets/refresh', name: 'api_corporation_assets_refresh', methods: ['POST'])]
    public function refreshCorporationAssets(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->security->getUser();

        $mainCharacter = $user->getMainCharacter();
        if ($mainCharacter === null) {
            return new JsonResponse(['error' => 'No main character set'], Response::HTTP_FORBIDDEN);
        }

        $corporationId = $mainCharacter->getCorporationId();

        // Check if any character in the corporation can access corp assets
        if (!$this->assetsSyncService->canSyncCorporationAssets($corporationId)) {
            return new JsonResponse([
                'error' => 'No character with corporation assets access. A director with esi-assets.read_corporation_assets.v1 scope must be linked.',
                'hasAccess' => false,
            ], Response::HTTP_FORBIDDEN);
        }

        // Use async=false query param to force synchronous processing (for testing)
        $async = $request->query->get('async', 'true') !== 'false';

        if ($async) {
            // Dispatch async message - returns immediately
            $this->messageBus->dispatch(new SyncCorporationAssets(
                $corporationId,
                $mainCharacter->getId()->toRfc4122()
            ));

            return new JsonResponse([
                'status' => 'pending',
                'message' => 'Corporation asset sync started. Refresh the page in a few seconds.',
            ]);
        }

        // Synchronous processing (fallback)
        try {
            $success = $this->assetsSyncService->syncCorporationAssetsForCorp($corporationId);

            if (!$success) {
                return new JsonResponse([
                    'error' => 'Failed to sync corporation assets - Director role may be required in-game',
                ], Response::HTTP_FORBIDDEN);
            }

            return new JsonResponse([
                'status' => 'completed',
                'message' => 'Corporation assets synced successfully',
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to sync corporation assets', [
                'corporationId' => $corporationId,
                'error' => $e->getMessage(),
            ]);

            return new JsonResponse([
                'error' => 'Failed to sync corporation assets: ' . $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/corporation/assets/status', name: 'api_corporation_assets_status', methods: ['GET'])]
    public function getCorporationAssetsStatus(): JsonResponse
    {
        /** @var User $user */
        $user = $this->security->getUser();

        $mainCharacter = $user->getMainCharacter();
        if ($mainCharacter === null) {
            return new JsonResponse(['error' => 'No main character set'], Response::HTTP_FORBIDDEN);
        }

        $corporationId = $mainCharacter->getCorporationId();
        $accessCharacter = $this->assetsSyncService->getCorpAssetsCharacter($corporationId);

        return new JsonResponse([
            'hasAccess' => $accessCharacter !== null,
            'accessCharacterName' => $accessCharacter?->getName(),
            'corporationId' => $corporationId,
            'corporationName' => $mainCharacter->getCorporationName(),
        ]);
    }

    private function formatAssetsResponse(array $assets, bool $includeDivision = false): JsonResponse
    {
        $result = array_map(function ($asset) use ($includeDivision) {
            $data = [
                'id' => $asset->getId()->toRfc4122(),
                'itemId' => $asset->getItemId(),
                'typeId' => $asset->getTypeId(),
                'typeName' => $asset->getTypeName(),
                'quantity' => $asset->getQuantity(),
                'locationId' => $asset->getLocationId(),
                'locationName' => $asset->getLocationName(),
                'locationType' => $asset->getLocationType(),
                'locationFlag' => $asset->getLocationFlag(),
                'solarSystemId' => $asset->getSolarSystemId(),
                'solarSystemName' => $asset->getSolarSystemName(),
                'itemName' => $asset->getItemName(),
                'cachedAt' => $asset->getCachedAt()->format('c'),
            ];

            if ($includeDivision) {
                $data['divisionName'] = $asset->getDivisionName();
            }

            return $data;
        }, $assets);

        return new JsonResponse([
            'total' => count($result),
            'items' => $result,
        ]);
    }
}
