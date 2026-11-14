<?php

declare(strict_types=1);

namespace App\State\Provider\Assets;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Assets\AssetChangeResource;
use App\ApiResource\Assets\AssetChangesPayload;
use App\ApiResource\Assets\CharacterAssetChangesResource;
use App\ApiResource\Assets\CorporationAssetChangesResource;
use App\Entity\AssetSyncChange;
use App\Entity\User;
use App\Repository\AssetSyncChangeRepository;
use App\Repository\CharacterRepository;
use App\Service\Assets\VisibleCorporationAssetsFinder;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * @implements ProviderInterface<AssetChangesPayload>
 */
class AssetChangesProvider implements ProviderInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly CharacterRepository $characterRepository,
        private readonly AssetSyncChangeRepository $changeRepository,
        private readonly VisibleCorporationAssetsFinder $visibleAssetsFinder,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): AssetChangesPayload
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        if (isset($uriVariables['characterId'])) {
            $characterId = (string) $uriVariables['characterId'];
            $resource = new CharacterAssetChangesResource();
            $resource->characterId = $characterId;

            return $this->fill($resource, $this->characterRows($user, $characterId));
        }

        return $this->fill(new CorporationAssetChangesResource(), $this->corporationRows($user));
    }

    /**
     * @return list<AssetSyncChange>
     */
    private function characterRows(User $user, string $characterId): array
    {
        $character = Uuid::isValid($characterId) ? $this->characterRepository->find(Uuid::fromString($characterId)) : null;

        if ($character === null || $character->getUser() !== $user) {
            throw new NotFoundHttpException('Character not found');
        }

        return $this->changeRepository->findForCharacter($character);
    }

    /**
     * @return list<AssetSyncChange>
     */
    private function corporationRows(User $user): array
    {
        $mainCharacter = $user->getMainCharacter();
        if ($mainCharacter === null) {
            throw new AccessDeniedHttpException('No main character set');
        }

        $corporationId = $mainCharacter->getCorporationId();

        return $this->changeRepository->findForCorporationDivisions(
            $corporationId,
            $this->visibleAssetsFinder->visibleDivisionNames($corporationId, $user),
        );
    }

    /**
     * @param list<AssetSyncChange> $rows
     */
    private function fill(AssetChangesPayload $resource, array $rows): AssetChangesPayload
    {
        $resource->total = \count($rows);
        $resource->changes = array_map(
            static fn (AssetSyncChange $row): AssetChangeResource => new AssetChangeResource(
                $row->getTypeId(),
                $row->getTypeName(),
                $row->getLocationId(),
                $row->getLocationName(),
                $row->getDivisionName(),
                $row->getQuantityDelta(),
            ),
            $rows,
        );

        if ($rows !== []) {
            $resource->syncedAt = $rows[0]->getSyncedAt()->format('c');
            $resource->previousSyncedAt = $rows[0]->getPreviousSyncedAt()->format('c');
        }

        return $resource;
    }
}
