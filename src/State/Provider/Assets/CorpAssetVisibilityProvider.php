<?php

declare(strict_types=1);

namespace App\State\Provider\Assets;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Assets\CorpAssetVisibilityResource;
use App\Constant\EveConstants;
use App\Entity\User;
use App\Repository\CachedAssetRepository;
use App\Repository\CorpAssetVisibilityRepository;
use App\Repository\UserRepository;
use App\Service\Assets\CorpVisibilityPlayers;
use App\Service\ESI\CharacterService;
use App\Service\ESI\CorporationDirectorAccess;
use App\Service\ESI\CorporationService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<CorpAssetVisibilityResource>
 */
class CorpAssetVisibilityProvider implements ProviderInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly CorpAssetVisibilityRepository $visibilityRepository,
        private readonly CachedAssetRepository $cachedAssetRepository,
        private readonly CorporationService $corporationService,
        private readonly CharacterService $characterService,
        private readonly CorporationDirectorAccess $directorAccess,
        private readonly UserRepository $userRepository,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): CorpAssetVisibilityResource
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
        $visibility = $this->visibilityRepository->findByCorporationId($corporationId);
        $allDivisions = $this->cachedAssetRepository->findDistinctDivisions($corporationId);

        // Fallback: if no cached assets yet, try ESI with the Director's token
        $directorUser = $visibility?->getConfiguredBy();
        if (empty($allDivisions) && $directorUser !== null) {
            foreach ($directorUser->getCharacters() as $directorCharacter) {
                if ($directorCharacter->getCorporationId() === $corporationId && $directorCharacter->getEveToken() !== null) {
                    $allDivisions = $this->corporationService->getDivisions($directorCharacter);
                    break;
                }
            }
        }

        $isDirector = $this->characterService->canReadCorporationAssets($mainCharacter);
        $restrictedDivisions = $visibility?->getRestrictedDivisions() ?? [];
        $players = $isDirector || $restrictedDivisions !== []
            ? $this->userRepository->findPlayersOfCorporation($corporationId)
            : [];

        $resource = new CorpAssetVisibilityResource();
        $resource->visibleDivisions = $visibility !== null
            ? $visibility->getVisibleDivisions()
            : array_keys($allDivisions);
        $resource->restrictedDivisions = CorpVisibilityPlayers::namedByDivision($restrictedDivisions, $players);
        $resource->shareBlueprintsOfHiddenDivisions = $visibility?->sharesBlueprintsOfHiddenDivisions() ?? true;
        $resource->accessibleDivisions = $visibility?->divisionsVisibleTo($user) ?? [];
        $resource->players = $isDirector
            ? array_map(CorpVisibilityPlayers::describe(...), $players)
            : [];
        $resource->directorConnected = $this->directorAccess->isConnected($corporationId, [EveConstants::CORPORATION_ASSETS_SCOPE]);
        $resource->allDivisions = $allDivisions;
        $resource->isDirector = $isDirector;
        $resource->configuredByName = $directorUser?->getMainCharacter()?->getName();
        $resource->updatedAt = $visibility?->getUpdatedAt()->format('c');

        return $resource;
    }
}
