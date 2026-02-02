<?php

declare(strict_types=1);

namespace App\State\Provider\Me;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Me\WalletEntryResource;
use App\ApiResource\Me\WalletResource;
use App\Entity\User;
use App\Service\ESI\EsiClient;
use App\Service\ESI\TokenManager;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<WalletResource>
 */
class WalletProvider implements ProviderInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly EsiClient $esiClient,
        private readonly TokenManager $tokenManager,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): WalletResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        // Prepare batch requests for concurrent execution
        $requests = [];
        $characterMap = [];

        foreach ($user->getCharacters() as $character) {
            $token = $character->getEveToken();
            if ($token === null) {
                continue;
            }

            try {
                // Refresh token if needed (do this sequentially before batch)
                if ($token->isExpiringSoon()) {
                    $this->tokenManager->refreshAccessToken($token);
                }

                $key = $character->getEveCharacterId();
                $requests[$key] = [
                    'endpoint' => "/characters/{$key}/wallet/",
                    'token' => $token,
                ];
                $characterMap[$key] = $character;
            } catch (\Throwable) {
                // Skip characters with token issues
                continue;
            }
        }

        // Execute all wallet requests concurrently with 10s timeout per request
        $balances = $this->esiClient->getScalarBatch($requests, 10);

        $wallets = [];
        $totalBalance = 0.0;

        foreach ($balances as $characterId => $balance) {
            if ($balance === null || !isset($characterMap[$characterId])) {
                continue;
            }

            $character = $characterMap[$characterId];

            $entry = new WalletEntryResource();
            $entry->characterId = $character->getId()?->toRfc4122() ?? '';
            $entry->characterName = $character->getName();
            $entry->isMain = $character->isMain();
            $entry->balance = (float) $balance;

            $wallets[] = $entry;
            $totalBalance += $entry->balance;
        }

        // Sort: main first
        usort($wallets, fn ($a, $b) => $b->isMain <=> $a->isMain);

        $resource = new WalletResource();
        $resource->wallets = $wallets;
        $resource->totalBalance = $totalBalance;

        return $resource;
    }
}
