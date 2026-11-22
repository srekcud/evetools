<?php

declare(strict_types=1);

namespace App\Service\Industry\Stock;

use App\Entity\CachedAsset;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectContainer;
use App\Entity\PersonalStockContainer;
use App\Entity\User;
use App\Exception\ContainerDeclaredByAnotherUserException;
use App\Exception\ContainerNamedLikeProjectContainerException;
use App\Exception\ContainerNotVisibleException;
use App\Exception\NotAContainerException;
use App\Repository\CachedAssetRepository;
use App\Repository\IndustryProjectRepository;
use App\Repository\PersonalStockContainerRepository;
use App\Service\Assets\VisibleCorporationAssetsFinder;
use App\Service\Industry\Project\AssetStations;
use Doctrine\ORM\EntityManagerInterface;

final readonly class PersonalStockContainerDeclarer
{
    public function __construct(
        private VisibleCorporationAssetsFinder $visibleAssetsFinder,
        private CachedAssetRepository $assetRepository,
        private PersonalStockContainerRepository $containerRepository,
        private IndustryProjectRepository $projectRepository,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function declare(User $user, int $itemId): PersonalStockContainer
    {
        $corporationId = $user->getCorporationId() ?? throw new ContainerNotVisibleException('The container is not visible to you');
        $visibleAssets = $this->visibleAssetsFinder->findVisibleAssets($corporationId, null, $user);
        $asset = $this->assetOf($visibleAssets, $itemId) ?? throw new ContainerNotVisibleException('The container is not visible to you');

        if (true !== $asset->isSingleton()) {
            throw new NotAContainerException('The asset is not a container');
        }

        $existing = $this->containerRepository->findByItemId($itemId);
        if (null !== $existing) {
            if (!self::isSameUser($existing->getUser(), $user)) {
                throw new ContainerDeclaredByAnotherUserException('The container is already a personal stock container of another user');
            }

            return $existing;
        }

        if ($this->isNamedLikeAProjectContainer($user, $asset)) {
            throw new ContainerNamedLikeProjectContainerException('The container has the name of a project container');
        }

        $station = (new AssetStations($visibleAssets, $this->assetRepository, $corporationId))->locationOf($asset);
        $declaration = new PersonalStockContainer(
            $user,
            $itemId,
            $asset->getItemName() ?? $asset->getTypeName() ?? (string) $itemId,
            $asset->getDivisionName(),
            $station?->locationId,
            $station?->name,
        );
        $this->entityManager->persist($declaration);

        return $declaration;
    }

    /**
     * @param CachedAsset[] $visibleAssets
     */
    private function assetOf(array $visibleAssets, int $itemId): ?CachedAsset
    {
        foreach ($visibleAssets as $asset) {
            if ($asset->getItemId() === $itemId) {
                return $asset;
            }
        }

        return null;
    }

    private function isNamedLikeAProjectContainer(User $user, CachedAsset $asset): bool
    {
        $assetName = $asset->getItemName();
        if (null === $assetName) {
            return false;
        }

        $assetKey = IndustryProjectContainer::keyOf($assetName);
        $openProjects = [...$this->projectRepository->findOpenOwnedBy($user), ...$this->projectRepository->findOpenOfAcceptedParticipant($user)];

        return array_any(
            $openProjects,
            static fn (IndustryProject $project): bool => array_any(
                $project->getContainerNames(),
                static fn (string $containerName): bool => IndustryProjectContainer::keyOf($containerName) === $assetKey,
            ),
        );
    }

    private static function isSameUser(User $first, User $second): bool
    {
        return $first === $second || (null !== $first->getId() && null !== $second->getId() && $first->getId()->equals($second->getId()));
    }
}
