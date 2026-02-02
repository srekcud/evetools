<?php

declare(strict_types=1);

namespace App\State\Provider\Me;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Me\CharacterResource;
use App\Entity\Character;
use App\Entity\User;
use App\Service\ESI\AuthenticationService;
use App\Service\ESI\TokenManager;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<CharacterResource[]>
 */
class CharacterCollectionProvider implements ProviderInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly TokenManager $tokenManager,
    ) {
    }

    /**
     * @return CharacterResource[]
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $characters = array_map(
            fn (Character $c) => $this->toResource($c),
            $user->getCharacters()->toArray()
        );

        usort($characters, function ($a, $b) {
            if ($a->isMain !== $b->isMain) {
                return $b->isMain <=> $a->isMain;
            }

            return strcmp($a->name, $b->name);
        });

        return $characters;
    }

    private function toResource(Character $character): CharacterResource
    {
        $token = $character->getEveToken();
        $hasValidToken = $token !== null && !empty($token->getScopes()) && $token->getScopes() !== [''];

        if ($hasValidToken && $token !== null) {
            try {
                $this->tokenManager->decryptRefreshToken($token->getRefreshTokenEncrypted());
            } catch (\RuntimeException) {
                $hasValidToken = false;
            }
        }

        $hasMissingScopes = false;
        if ($hasValidToken && $token !== null) {
            $tokenScopes = $token->getScopes();
            $requiredScopes = AuthenticationService::getRequiredScopes();
            $missingScopes = array_diff($requiredScopes, $tokenScopes);
            $hasMissingScopes = !empty($missingScopes);
        }

        $resource = new CharacterResource();
        $resource->id = $character->getId()?->toRfc4122() ?? '';
        $resource->eveCharacterId = $character->getEveCharacterId();
        $resource->name = $character->getName();
        $resource->corporationId = $character->getCorporationId();
        $resource->corporationName = $character->getCorporationName();
        $resource->allianceId = $character->getAllianceId();
        $resource->allianceName = $character->getAllianceName();
        $resource->isMain = $character->isMain();
        $resource->hasValidToken = $hasValidToken && !$hasMissingScopes;
        $resource->hasMissingScopes = $hasMissingScopes;
        $resource->lastSyncAt = $character->getLastSyncAt()?->format('c');

        return $resource;
    }
}
