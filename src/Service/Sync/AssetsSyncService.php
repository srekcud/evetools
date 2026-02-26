<?php

declare(strict_types=1);

namespace App\Service\Sync;

use App\Entity\CachedAsset;
use App\Entity\Character;
use App\Repository\CachedAssetRepository;
use App\Repository\CharacterRepository;
use App\Repository\CorpAssetVisibilityRepository;
use App\Service\ESI\AssetsService;
use App\Service\ESI\CorporationService;
use App\Service\Mercure\MercurePublisherService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class AssetsSyncService
{
    private const SYNC_INTERVAL_MINUTES = 30;

    public function __construct(
        private readonly AssetsService $assetsService,
        private readonly CorporationService $corporationService,
        private readonly CachedAssetRepository $cachedAssetRepository,
        private readonly CharacterRepository $characterRepository,
        private readonly CorpAssetVisibilityRepository $visibilityRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
        private readonly MercurePublisherService $mercurePublisher,
    ) {
    }

    public function syncCharacterAssets(Character $character): void
    {
        $userId = $character->getUser()?->getId()?->toRfc4122();

        // Notify sync started
        if ($userId !== null) {
            $this->mercurePublisher->syncStarted($userId, 'character-assets', 'Fetching character assets...');
        }

        try {
            // Delete existing cached assets for this character
            $this->cachedAssetRepository->deleteByCharacter($character);

            // Fetch fresh assets from ESI
            $assets = $this->assetsService->getCharacterAssets($character);

            // Notify progress
            if ($userId !== null) {
                $this->mercurePublisher->syncProgress(
                    $userId,
                    'character-assets',
                    50,
                    sprintf('Processing %d assets...', count($assets))
                );
            }

            // Cache the assets
            foreach ($assets as $assetDto) {
                $cachedAsset = new CachedAsset();
                $cachedAsset->setItemId($assetDto->itemId);
                $cachedAsset->setTypeId($assetDto->typeId);
                $cachedAsset->setTypeName($assetDto->typeName);
                $cachedAsset->setQuantity($assetDto->quantity);
                $cachedAsset->setLocationId($assetDto->locationId);
                $cachedAsset->setLocationName($assetDto->locationName);
                $cachedAsset->setLocationType($assetDto->locationType);
                $cachedAsset->setLocationFlag($assetDto->locationFlag);
                $cachedAsset->setSolarSystemId($assetDto->solarSystemId);
                $cachedAsset->setSolarSystemName($assetDto->solarSystemName);
                $cachedAsset->setItemName($assetDto->itemName);
                $cachedAsset->setCharacter($character);
                $cachedAsset->setIsCorporationAsset(false);

                $this->entityManager->persist($cachedAsset);
            }

            // Update character last sync time
            $character->updateLastSync();

            $this->entityManager->flush();

            // Notify sync completed
            if ($userId !== null) {
                $this->mercurePublisher->syncCompleted(
                    $userId,
                    'character-assets',
                    'Sync completed',
                    ['count' => count($assets)]
                );
            }
        } catch (\Throwable $e) {
            if ($userId !== null) {
                $this->mercurePublisher->syncError($userId, 'character-assets', $e->getMessage());
            }
            throw $e;
        }
    }

    /**
     * Sync corporation assets using a specific character's token.
     * The character must have the required scope and Director role.
     */
    public function syncCorporationAssets(Character $character): void
    {
        $corporationId = $character->getCorporationId();
        $userId = $character->getUser()?->getId()?->toRfc4122();

        // Notify sync started
        if ($userId !== null) {
            $this->mercurePublisher->syncStarted($userId, 'corporation-assets', 'Fetching corporation assets...');
        }

        try {
            // Get division names using the same Director character
            $divisions = [];
            try {
                $divisions = $this->corporationService->getDivisions($character);
            } catch (\Throwable $e) {
                $this->logger->warning('Failed to get corporation divisions', [
                    'corporationId' => $corporationId,
                    'error' => $e->getMessage(),
                ]);
            }

            // Delete existing cached corp assets
            $this->cachedAssetRepository->deleteByCorporationId($corporationId);

            // Fetch fresh assets from ESI
            $assets = $this->assetsService->getCorporationAssets($character);

            // Notify progress
            if ($userId !== null) {
                $this->mercurePublisher->syncProgress(
                    $userId,
                    'corporation-assets',
                    50,
                    sprintf('Processing %d assets...', count($assets))
                );
            }

            // Cache the assets with division names
            foreach ($assets as $assetDto) {
                $cachedAsset = new CachedAsset();
                $cachedAsset->setItemId($assetDto->itemId);
                $cachedAsset->setTypeId($assetDto->typeId);
                $cachedAsset->setTypeName($assetDto->typeName);
                $cachedAsset->setQuantity($assetDto->quantity);
                $cachedAsset->setLocationId($assetDto->locationId);
                $cachedAsset->setLocationName($assetDto->locationName);
                $cachedAsset->setLocationType($assetDto->locationType);
                $cachedAsset->setLocationFlag($assetDto->locationFlag);
                $cachedAsset->setSolarSystemId($assetDto->solarSystemId);
                $cachedAsset->setSolarSystemName($assetDto->solarSystemName);
                $cachedAsset->setItemName($assetDto->itemName);
                $cachedAsset->setCorporationId($corporationId);
                $cachedAsset->setIsCorporationAsset(true);

                // Map location flag to division name
                if ($assetDto->locationFlag !== null) {
                    $divisionNumber = $this->extractDivisionNumber($assetDto->locationFlag);

                    if ($divisionNumber !== null && isset($divisions[$divisionNumber])) {
                        $cachedAsset->setDivisionName($divisions[$divisionNumber]);
                    }
                }

                $this->entityManager->persist($cachedAsset);
            }

            $this->entityManager->flush();

            // Notify sync completed
            if ($userId !== null) {
                $this->mercurePublisher->syncCompleted(
                    $userId,
                    'corporation-assets',
                    'Sync completed',
                    ['count' => count($assets)]
                );
            }
        } catch (\Throwable $e) {
            if ($userId !== null) {
                $this->mercurePublisher->syncError($userId, 'corporation-assets', $e->getMessage());
            }
            throw $e;
        }
    }

    /**
     * Sync corporation assets for a given corporation ID.
     * Automatically finds a character with the required access.
     */
    public function syncCorporationAssetsForCorp(int $corporationId): bool
    {
        $character = $this->getCorpAssetsCharacter($corporationId);

        if ($character === null) {
            $this->logger->info('No character with corporation assets access found', [
                'corporationId' => $corporationId,
            ]);
            return false;
        }

        $this->logger->info('Syncing corporation assets using character', [
            'corporationId' => $corporationId,
            'characterName' => $character->getName(),
        ]);

        try {
            $this->syncCorporationAssets($character);
            return true;
        } catch (\Throwable $e) {
            $this->logger->error('Failed to sync corporation assets', [
                'corporationId' => $corporationId,
                'characterName' => $character->getName(),
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    public function shouldSync(Character $character): bool
    {
        $lastSync = $character->getLastSyncAt();

        if ($lastSync === null) {
            return true;
        }

        $threshold = new \DateTimeImmutable('-' . self::SYNC_INTERVAL_MINUTES . ' minutes');

        return $lastSync < $threshold;
    }

    public function canSync(Character $character): bool
    {
        // Check if character has a valid token
        $token = $character->getEveToken();

        if ($token === null) {
            return false;
        }

        // Check if user auth is valid
        $user = $character->getUser();

        return $user !== null && $user->isAuthValid();
    }

    /**
     * Check if corporation assets can be synced for the given corporation.
     * Returns true if a character with the required scope is available (preferring the Director who configured visibility).
     */
    public function canSyncCorporationAssets(int $corporationId): bool
    {
        return $this->getCorpAssetsCharacter($corporationId) !== null;
    }

    /**
     * Get the character that will be used to sync corporation assets.
     * Prefers the Director who configured visibility, falls back to any character with the scope.
     */
    public function getCorpAssetsCharacter(int $corporationId): ?Character
    {
        // Prefer the Director who configured visibility
        $visibility = $this->visibilityRepository->findByCorporationId($corporationId);
        if ($visibility !== null) {
            $directorUser = $visibility->getConfiguredBy();
            foreach ($directorUser->getCharacters() as $character) {
                if ($character->getCorporationId() === $corporationId) {
                    $token = $character->getEveToken();
                    if ($token !== null && $token->hasScope('esi-assets.read_corporation_assets.v1')) {
                        return $character;
                    }
                }
            }
        }

        // Fallback: any character in the corp with the scope
        return $this->characterRepository->findWithCorpAssetsAccess($corporationId);
    }

    private function extractDivisionNumber(string $locationFlag): ?int
    {
        // Location flags for corp hangars: CorpSAG1, CorpSAG2, etc.
        if (preg_match('/^CorpSAG(\d+)$/', $locationFlag, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }
}
