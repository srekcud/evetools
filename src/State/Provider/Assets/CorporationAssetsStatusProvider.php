<?php

declare(strict_types=1);

namespace App\State\Provider\Assets;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Assets\CorporationAssetsStatusResource;
use App\Entity\User;
use App\Service\Sync\AssetsSyncService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<CorporationAssetsStatusResource>
 */
class CorporationAssetsStatusProvider implements ProviderInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly AssetsSyncService $assetsSyncService,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): CorporationAssetsStatusResource
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
        $accessCharacter = $this->assetsSyncService->getCorpAssetsCharacter($corporationId);

        $resource = new CorporationAssetsStatusResource();
        $resource->hasAccess = $accessCharacter !== null;
        $resource->accessCharacterName = $accessCharacter?->getName();
        $resource->corporationId = $corporationId;
        $resource->corporationName = $mainCharacter->getCorporationName();

        return $resource;
    }
}
