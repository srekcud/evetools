<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\CachedStructure;
use App\Entity\EveToken;
use App\Exception\EsiApiException;
use App\Message\WarmupStructureOwnersMessage;
use App\Repository\CachedAssetRepository;
use App\Repository\CachedStructureRepository;
use App\Repository\CharacterRepository;
use App\Repository\UserRepository;
use App\Service\ESI\EsiClient;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class WarmupStructureOwnersHandler
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly CharacterRepository $characterRepository,
        private readonly CachedAssetRepository $cachedAssetRepository,
        private readonly CachedStructureRepository $cachedStructureRepository,
        private readonly EsiClient $esiClient,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(WarmupStructureOwnersMessage $message): void
    {
        $user = $this->userRepository->find($message->userId);
        if ($user === null) {
            return;
        }

        // Collect all available tokens from characters in the same corporation
        // This distributes rate limits across multiple characters
        $tokens = $this->collectCorporationTokens($user);

        if (empty($tokens)) {
            $this->logger->warning('No valid token for structure owner warmup', [
                'userId' => $message->userId,
            ]);
            return;
        }

        $this->logger->info('Collected tokens for warmup', [
            'userId' => $message->userId,
            'tokenCount' => count($tokens),
        ]);

        // Get unique structure IDs from user's assets
        $structureIds = [];
        foreach ($user->getCharacters() as $character) {
            $assets = $this->cachedAssetRepository->findByCharacter($character);
            foreach ($assets as $asset) {
                $locationId = $asset->getLocationId();
                if ($locationId > 1000000000000) {
                    $structureIds[$locationId] = true;
                }
            }
        }

        if (empty($structureIds)) {
            return;
        }

        // Get existing cached structures
        $cachedStructures = $this->cachedStructureRepository->findByStructureIds(array_keys($structureIds));

        // Find structures without owner cached
        $structuresToFetch = [];
        foreach ($structureIds as $structureId => $_) {
            $cached = $cachedStructures[$structureId] ?? null;
            if ($cached === null || $cached->getOwnerCorporationId() === null) {
                $structuresToFetch[] = $structureId;
            }
        }

        if (empty($structuresToFetch)) {
            $this->logger->info('All structure owners already cached', [
                'userId' => $message->userId,
                'structureCount' => count($structureIds),
            ]);
            return;
        }

        $this->logger->info('Warming up structure owner cache', [
            'userId' => $message->userId,
            'structuresToFetch' => count($structuresToFetch),
        ]);

        $fetched = 0;
        $errors = 0;
        $maxErrors = 20; // Stop after 20 errors to avoid rate limiting
        $tokenIndex = 0;
        $tokenCount = count($tokens);

        foreach ($structuresToFetch as $structureId) {
            // Stop if too many errors to avoid 420
            if ($errors >= $maxErrors) {
                $this->logger->warning('Stopping warmup due to too many errors', [
                    'userId' => $message->userId,
                    'errors' => $errors,
                    'fetched' => $fetched,
                ]);
                break;
            }

            // Rotate tokens to distribute rate limits
            $token = $tokens[$tokenIndex % $tokenCount];
            $tokenIndex++;

            try {
                $structureInfo = $this->esiClient->get("/universe/structures/{$structureId}/", $token);
                $ownerId = $structureInfo['owner_id'] ?? null;

                $cached = $cachedStructures[$structureId] ?? null;
                $typeId = $structureInfo['type_id'] ?? null;
                if ($cached !== null) {
                    $cached->setOwnerCorporationId($ownerId);
                    $cached->setTypeId($typeId);
                    $cached->setName($structureInfo['name'] ?? $cached->getName());
                    $cached->setSolarSystemId($structureInfo['solar_system_id'] ?? $cached->getSolarSystemId());
                } else {
                    $newCached = new CachedStructure();
                    $newCached->setStructureId($structureId);
                    $newCached->setName($structureInfo['name'] ?? "Structure #{$structureId}");
                    $newCached->setSolarSystemId($structureInfo['solar_system_id'] ?? null);
                    $newCached->setOwnerCorporationId($ownerId);
                    $newCached->setTypeId($typeId);
                    $this->entityManager->persist($newCached);
                    $cachedStructures[$structureId] = $newCached;
                }

                $fetched++;

                // Flush every 10 structures to avoid memory issues
                if ($fetched % 10 === 0) {
                    $this->entityManager->flush();
                }

                // Small delay between successful requests to be nice to ESI
                usleep(100000); // 100ms
            } catch (EsiApiException $e) {
                $errors++;

                // Stop immediately if rate limited
                if ($e->statusCode === 420 || str_contains($e->getMessage(), 'Error limited')) {
                    $this->logger->warning('Rate limited during warmup, stopping', [
                        'userId' => $message->userId,
                        'structureId' => $structureId,
                    ]);
                    break;
                }

                $this->logger->debug('Could not fetch structure owner during warmup', [
                    'structureId' => $structureId,
                    'error' => $e->getMessage(),
                ]);
            } catch (\Throwable $e) {
                $errors++;
                $this->logger->debug('Could not fetch structure owner during warmup', [
                    'structureId' => $structureId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->entityManager->flush();

        $this->logger->info('Structure owner warmup completed', [
            'userId' => $message->userId,
            'fetched' => $fetched,
            'total' => count($structuresToFetch),
        ]);
    }

    /**
     * Collect all available tokens from characters in the user's corporation.
     * This allows distributing ESI calls across multiple characters to avoid rate limits.
     *
     * @return EveToken[]
     */
    private function collectCorporationTokens($user): array
    {
        $tokens = [];
        $corporationId = $user->getCorporationId();

        // First, add tokens from the user's own characters
        foreach ($user->getCharacters() as $character) {
            $token = $character->getEveToken();
            if ($token !== null) {
                $tokens[] = $token;
            }
        }

        // If user has a corporation, get tokens from other corp members
        if ($corporationId !== null && count($tokens) < 5) {
            $corpCharacters = $this->characterRepository->findByCorporationId($corporationId);
            foreach ($corpCharacters as $character) {
                // Skip if already added (user's own characters)
                if ($character->getUser() === $user) {
                    continue;
                }
                $token = $character->getEveToken();
                if ($token !== null) {
                    $tokens[] = $token;
                    // Limit to 5 tokens total to avoid complexity
                    if (count($tokens) >= 5) {
                        break;
                    }
                }
            }
        }

        return $tokens;
    }
}
