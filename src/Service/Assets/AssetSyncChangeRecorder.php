<?php

declare(strict_types=1);

namespace App\Service\Assets;

use App\Dto\AssetQuantityChange;
use App\Entity\AssetSyncChange;
use App\Entity\CachedAsset;
use App\Entity\Character;
use App\Repository\AssetSyncChangeRepository;
use Doctrine\ORM\EntityManagerInterface;

class AssetSyncChangeRecorder
{
    public function __construct(
        private readonly AssetSyncChangeCalculator $calculator,
        private readonly AssetSyncChangeRepository $repository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @param array<CachedAsset> $before
     * @param array<CachedAsset> $after
     */
    public function recordForCharacter(Character $character, array $before, array $after, \DateTimeImmutable $syncedAt): void
    {
        if ($before === []) {
            return;
        }

        $changes = $this->calculator->calculate($before, $after);
        if ($changes === []) {
            return;
        }

        $this->repository->deleteForCharacter($character);
        $this->persistAll($character, null, $changes, $syncedAt, $this->mostRecentCacheTime($before));
    }

    /**
     * @param array<CachedAsset> $before
     * @param array<CachedAsset> $after
     */
    public function recordForCorporation(int $corporationId, array $before, array $after, \DateTimeImmutable $syncedAt): void
    {
        if ($before === []) {
            return;
        }

        $inDivision = static fn (CachedAsset $asset): bool => $asset->getDivisionName() !== null;
        $changes = $this->calculator->calculate(array_filter($before, $inDivision), array_filter($after, $inDivision));
        if ($changes === []) {
            return;
        }

        $this->repository->deleteForCorporation($corporationId);
        $this->persistAll(null, $corporationId, $changes, $syncedAt, $this->mostRecentCacheTime($before));
    }

    /**
     * @param list<AssetQuantityChange> $changes
     */
    private function persistAll(?Character $character, ?int $corporationId, array $changes, \DateTimeImmutable $syncedAt, \DateTimeImmutable $previousSyncedAt): void
    {
        foreach ($changes as $change) {
            $this->entityManager->persist(new AssetSyncChange($character, $corporationId, $change, $syncedAt, $previousSyncedAt));
        }
    }

    /**
     * @param non-empty-array<CachedAsset> $assets
     */
    private function mostRecentCacheTime(array $assets): \DateTimeImmutable
    {
        return max(array_map(static fn (CachedAsset $asset): \DateTimeImmutable => $asset->getCachedAt(), $assets));
    }
}
