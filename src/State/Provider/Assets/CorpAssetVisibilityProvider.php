<?php

declare(strict_types=1);

namespace App\State\Provider\Assets;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Assets\CorpAssetVisibilityResource;
use App\Entity\User;
use App\Repository\CorpAssetVisibilityRepository;
use App\Service\ESI\CharacterService;
use App\Service\ESI\CorporationService;
use App\Repository\CharacterRepository;
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
        private readonly CharacterService $characterService,
        private readonly CorporationService $corporationService,
        private readonly CharacterRepository $characterRepository,
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
        $isDirector = $this->characterService->canReadCorporationAssets($mainCharacter);

        // Use a character with divisions scope (non-directors can't access ESI divisions endpoint)
        $divisionsCharacter = $this->characterRepository->findWithCorpDivisionsAccess($corporationId);
        $allDivisions = $this->corporationService->getDivisions($divisionsCharacter ?? $mainCharacter);

        $visibility = $this->visibilityRepository->findByCorporationId($corporationId);

        $resource = new CorpAssetVisibilityResource();
        $resource->visibleDivisions = $visibility !== null
            ? $visibility->getVisibleDivisions()
            : array_keys($allDivisions);
        $resource->allDivisions = $allDivisions;
        $resource->isDirector = $isDirector;
        $resource->configuredByName = $visibility?->getConfiguredBy()->getMainCharacter()?->getName();
        $resource->updatedAt = $visibility?->getUpdatedAt()->format('c');

        return $resource;
    }
}
