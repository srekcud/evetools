<?php

declare(strict_types=1);

namespace App\State\Provider\Me;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Me\WalletEntryResource;
use App\ApiResource\Me\WalletResource;
use App\Entity\Character;
use App\Entity\EveToken;
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
        /** @var array<string, array{endpoint: string, token: ?EveToken}> $requests */
        $requests = [];
        /** @var array<string, Character> $charactersByEveId */
        $charactersByEveId = [];

        foreach ($user->getCharacters() as $character) {
            $token = $character->getEveToken();
            if ($token === null) {
                continue;
            }

            $key = (string) $character->getEveCharacterId();
            $charactersByEveId[$key] = $character;

            try {
                // Refresh token if needed (do this sequentially before batch)
                $this->tokenManager->getValidAccessToken($token);
            } catch (\Throwable) {
                // No request: the character is reported below with an unknown balance
                continue;
            }

            $requests[$key] = [
                'endpoint' => "/characters/{$key}/wallet/",
                'token' => $token,
            ];
        }

        // Execute all wallet requests concurrently with 10s timeout per request
        /** @phpstan-var array<string, array{endpoint: string, token: ?EveToken}> $requests */
        $balances = $this->esiClient->getScalarBatch($requests, 10);

        $resource = new WalletResource();

        foreach ($charactersByEveId as $eveCharacterId => $character) {
            $balance = $balances[$eveCharacterId] ?? null;

            $entry = new WalletEntryResource();
            $entry->characterId = $character->getId()?->toRfc4122() ?? '';
            $entry->characterName = $character->getName();
            $entry->isMain = $character->isMain();
            $entry->balance = $balance === null ? null : (float) $balance;

            $resource->wallets[] = $entry;

            if ($entry->balance === null) {
                $resource->incomplete = true;
            } else {
                $resource->totalBalance += $entry->balance;
            }
        }

        // Sort: main first
        usort($resource->wallets, fn ($a, $b) => $b->isMain <=> $a->isMain);

        return $resource;
    }
}
