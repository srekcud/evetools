<?php

declare(strict_types=1);

namespace App\Service\Assets;

use App\Entity\CachedAsset;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectContainer;
use App\Entity\User;
use App\Repository\IndustryProjectRepository;
use App\Repository\PinnedContainerRepository;

class PinnedContainerCatalog
{
    public function __construct(
        private readonly VisibleCorporationAssetsFinder $visibleAssetsFinder,
        private readonly PinnedContainerRepository $pinRepository,
        private readonly IndustryProjectRepository $projectRepository,
    ) {
    }

    /**
     * @return list<PinnedContainerEntry>
     */
    public function entriesOf(User $user): array
    {
        $visibleAssets = $this->visibleAssetsOf($user);
        $assetsByItemId = [];
        $contentsByContainerId = [];
        foreach ($visibleAssets as $asset) {
            $assetsByItemId[$asset->getItemId()] = $asset;
            $contentsByContainerId[$asset->getLocationId()][] = $asset;
        }

        $entries = [];
        $projectPinnedItemIds = [];
        foreach ($this->activeProjectsOf($user) as $project) {
            $projectId = $project->getId()->toRfc4122();
            foreach ($project->getContainerNames() as $containerName) {
                $wantedKey = IndustryProjectContainer::keyOf($containerName);
                $matches = array_filter(
                    $visibleAssets,
                    static fn (CachedAsset $asset): bool => $asset->getItemName() !== null && IndustryProjectContainer::keyOf($asset->getItemName()) === $wantedKey,
                );

                if ($matches === []) {
                    $entries[] = new PinnedContainerEntry($projectId.':missing:'.sha1($wantedKey), null, $containerName, null, null, null, null, false, $projectId, $project->getName());

                    continue;
                }

                foreach ($matches as $asset) {
                    $projectPinnedItemIds[$asset->getItemId()] = true;
                    $entries[] = $this->foundEntry($asset, $projectId.':'.$asset->getItemId(), $contentsByContainerId, $projectId, $project->getName());
                }
            }
        }

        foreach ($this->pinRepository->findByUser($user) as $pin) {
            if (isset($projectPinnedItemIds[$pin->getItemId()])) {
                continue;
            }

            $asset = $assetsByItemId[$pin->getItemId()] ?? null;
            $entries[] = $asset === null
                ? new PinnedContainerEntry((string) $pin->getItemId(), $pin->getItemId(), $pin->getName(), null, null, null, null, false, null, null)
                : $this->foundEntry($asset, (string) $pin->getItemId(), $contentsByContainerId, null, null);
        }

        return $entries;
    }

    public function visibleAssetOf(User $user, int $itemId): ?CachedAsset
    {
        foreach ($this->visibleAssetsOf($user) as $asset) {
            if ($asset->getItemId() === $itemId) {
                return $asset;
            }
        }

        return null;
    }

    public static function nameOf(CachedAsset $asset): string
    {
        return $asset->getItemName() ?? $asset->getTypeName() ?? (string) $asset->getItemId();
    }

    /**
     * @return array<CachedAsset>
     */
    private function visibleAssetsOf(User $user): array
    {
        $mainCharacter = $user->getMainCharacter();
        if ($mainCharacter === null) {
            return [];
        }

        return $this->visibleAssetsFinder->findVisibleAssets($mainCharacter->getCorporationId(), null, $user);
    }

    /**
     * @return list<IndustryProject>
     */
    private function activeProjectsOf(User $user): array
    {
        $projectsById = [];
        foreach ([...$this->projectRepository->findOpenOwnedBy($user), ...$this->projectRepository->findOpenOfAcceptedParticipant($user)] as $project) {
            $projectsById[$project->getId()->toRfc4122()] = $project;
        }

        $projects = array_values($projectsById);
        usort($projects, static fn (IndustryProject $first, IndustryProject $second): int => strcasecmp((string) $first->getName(), (string) $second->getName()));

        return $projects;
    }

    /**
     * @param array<int, list<CachedAsset>> $contentsByContainerId
     */
    private function foundEntry(CachedAsset $asset, string $key, array $contentsByContainerId, ?string $projectId, ?string $projectName): PinnedContainerEntry
    {
        return new PinnedContainerEntry(
            $key,
            $asset->getItemId(),
            self::nameOf($asset),
            $asset->getLocationName(),
            $asset->getSolarSystemName(),
            $asset->getDivisionName(),
            $this->quantityStoredIn($asset->getItemId(), $contentsByContainerId),
            true,
            $projectId,
            $projectName,
        );
    }

    /**
     * @param array<int, list<CachedAsset>> $contentsByContainerId
     */
    private function quantityStoredIn(int $containerItemId, array $contentsByContainerId): int
    {
        $quantity = 0;
        $pendingContainerIds = [$containerItemId];
        $visitedContainerIds = [];

        while ($pendingContainerIds !== []) {
            $currentContainerId = array_pop($pendingContainerIds);
            if (isset($visitedContainerIds[$currentContainerId])) {
                continue;
            }
            $visitedContainerIds[$currentContainerId] = true;

            foreach ($contentsByContainerId[$currentContainerId] ?? [] as $content) {
                $quantity += $content->getQuantity();
                $pendingContainerIds[] = $content->getItemId();
            }
        }

        return $quantity;
    }
}
