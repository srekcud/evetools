<?php

declare(strict_types=1);

namespace App\Service\MiningOperation;

use App\Entity\CachedAsset;
use App\Entity\MiningOperation;
use App\Entity\MiningOperationMember;
use App\Entity\User;
use App\Enum\MinedQuantityState;
use App\Enum\MiningFleetStorageCheck;
use App\Enum\MiningFleetStorageDelivery;
use App\Enum\MiningFleetStorageUnknownReason;
use App\Enum\MiningOperationStatus;
use App\Enum\MiningSplitMode;
use App\Repository\CachedAssetRepository;
use App\Repository\CorpAssetVisibilityRepository;
use App\Service\Assets\CorporationContainerContents;
use App\Service\Assets\VisibleCorporationAssetsFinder;
use App\Service\OreValueService;
use App\Service\TypeNameResolver;

final readonly class MiningFleetStorageVerifier
{
    public function __construct(
        private VisibleCorporationAssetsFinder $visibleAssetsFinder,
        private CachedAssetRepository $cachedAssetRepository,
        private CorpAssetVisibilityRepository $visibilityRepository,
        private OreValueService $oreValueService,
        private MinedQuantityCalculator $quantityCalculator,
        private TypeNameResolver $typeNameResolver,
        private CorporationContainerContents $containerContents = new CorporationContainerContents(),
    ) {
    }

    public function verify(MiningOperation $operation, User $reader): MiningFleetStorageVerification
    {
        $containerName = $operation->getFleetStorageContainerName();
        if ($operation->getSplitMode() === MiningSplitMode::EachForThemselves) {
            return $this->nothingDelivered($containerName);
        }

        $expectedQuantities = $this->expectedQuantitiesOf($operation);
        $notEndedReason = $this->notEndedReasonOf($operation->getStatus());
        if ($notEndedReason !== null) {
            return $this->unknown($notEndedReason, $containerName, null, $expectedQuantities);
        }
        if ($expectedQuantities === []) {
            return $this->nothingDelivered($containerName);
        }

        $corporationId = $operation->getCorporationId();
        if ($containerName === null) {
            return $this->unknown(MiningFleetStorageUnknownReason::NoContainerName, $containerName, null, $expectedQuantities);
        }
        if ($corporationId === null) {
            return $this->unknown(MiningFleetStorageUnknownReason::NoCorporation, $containerName, null, $expectedQuantities);
        }
        if ($reader->getCorporationId() !== $corporationId) {
            return $this->unknown(MiningFleetStorageUnknownReason::NotCorporationMember, $containerName, null, $expectedQuantities);
        }
        if (!$this->cachedAssetRepository->hasCorporationAssets($corporationId)) {
            return $this->unknown(MiningFleetStorageUnknownReason::CorporationAssetsNotSynced, $containerName, null, $expectedQuantities);
        }
        if ($this->hasNoVisibleDivision($corporationId)) {
            return $this->unknown(MiningFleetStorageUnknownReason::NoVisibleDivision, $containerName, null, $expectedQuantities);
        }

        $visibleAssets = array_values($this->visibleAssetsFinder->findVisibleAssets($corporationId));
        $assetsReadAt = $this->latestCachedAtOf($visibleAssets);
        $endedAt = $operation->getEndedAt() ?? throw new \LogicException('An operation whose shares are being settled has ended');
        if ($assetsReadAt !== null && $assetsReadAt < $endedAt) {
            return $this->unknown(MiningFleetStorageUnknownReason::AssetsSyncedBeforeEnd, $containerName, $assetsReadAt, $expectedQuantities);
        }

        $foundQuantities = $this->containerContents->quantitiesIn($visibleAssets, $containerName);
        if ($foundQuantities === null) {
            return $this->unknown(MiningFleetStorageUnknownReason::ContainerNotFound, $containerName, $assetsReadAt, $expectedQuantities);
        }
        if ($expectedQuantities === null) {
            return $this->unknown(MiningFleetStorageUnknownReason::DeliveredQuantityUnknown, $containerName, $assetsReadAt, null);
        }

        return $this->compare($expectedQuantities, $foundQuantities, $containerName, $assetsReadAt);
    }

    private function notEndedReasonOf(MiningOperationStatus $status): ?MiningFleetStorageUnknownReason
    {
        return match ($status) {
            MiningOperationStatus::Planned, MiningOperationStatus::InProgress => MiningFleetStorageUnknownReason::OperationNotEnded,
            MiningOperationStatus::Settled, MiningOperationStatus::Completed => MiningFleetStorageUnknownReason::OperationArchived,
            MiningOperationStatus::Closing, MiningOperationStatus::Shares => null,
        };
    }

    /**
     * @return array<int, int>|null typeId => units the depositors are expected to have delivered, null when a delivery is unknown
     */
    private function expectedQuantitiesOf(MiningOperation $operation): ?array
    {
        $expected = [];
        $unknown = false;
        foreach ($operation->participantsInLedgerOrder() as $member) {
            $delivered = match ($member->getFleetStorageDelivery()) {
                MiningFleetStorageDelivery::None => [],
                MiningFleetStorageDelivery::Partial => $member->getFleetStorageQuantities() ?? throw new \LogicException('A partial delivery declares its quantities'),
                MiningFleetStorageDelivery::All => $this->minedQuantitiesOf($member),
            };
            if ($delivered === null) {
                $unknown = true;

                continue;
            }
            foreach ($delivered as $typeId => $quantity) {
                $expected[$typeId] = ($expected[$typeId] ?? 0) + $quantity;
            }
        }

        return $unknown ? null : $expected;
    }

    /**
     * @return array<int, int>|null
     */
    private function minedQuantitiesOf(MiningOperationMember $member): ?array
    {
        $quantity = $this->quantityCalculator->ofParticipant($member);

        return $quantity->state === MinedQuantityState::Known
            ? array_filter($quantity->quantitiesByTypeId, static fn (int $minedQuantity): bool => $minedQuantity > 0)
            : null;
    }

    private function hasNoVisibleDivision(int $corporationId): bool
    {
        $visibility = $this->visibilityRepository->findByCorporationId($corporationId);

        return $visibility === null || $visibility->getVisibleDivisions() === [];
    }

    /**
     * @param list<CachedAsset> $assets
     */
    private function latestCachedAtOf(array $assets): ?\DateTimeImmutable
    {
        $latest = null;
        foreach ($assets as $asset) {
            if ($latest === null || $asset->getCachedAt() > $latest) {
                $latest = $asset->getCachedAt();
            }
        }

        return $latest;
    }

    /**
     * @param array<int, int> $expectedQuantities
     * @param array<int, int> $foundQuantities
     */
    private function compare(array $expectedQuantities, array $foundQuantities, string $containerName, ?\DateTimeImmutable $assetsReadAt): MiningFleetStorageVerification
    {
        $typeIds = array_keys($expectedQuantities);
        sort($typeIds);
        $compressedTypeIds = array_map(
            static fn (array $prices): ?int => $prices['compressedTypeId'],
            $this->oreValueService->getBatchOrePrices($typeIds, null, null),
        );
        $typeNames = $this->typeNameResolver->findNames($typeIds);

        $lines = [];
        $allVerified = true;
        foreach ($typeIds as $typeId) {
            $expected = $expectedQuantities[$typeId];
            $compressedTypeId = $compressedTypeIds[$typeId] ?? null;
            $found = ($foundQuantities[$typeId] ?? 0) + ($compressedTypeId === null ? 0 : ($foundQuantities[$compressedTypeId] ?? 0));
            $verified = $found >= $expected;
            $allVerified = $allVerified && $verified;
            $lines[] = new MiningFleetStorageVerificationLine(
                $typeId,
                $typeNames[$typeId] ?? sprintf('Type %d', $typeId),
                $expected,
                $found,
                $verified ? 0 : $expected - $found,
                $verified ? MiningFleetStorageCheck::Verified : MiningFleetStorageCheck::Short,
            );
        }
        usort($lines, static fn (MiningFleetStorageVerificationLine $left, MiningFleetStorageVerificationLine $right): int => $left->typeName <=> $right->typeName);

        return new MiningFleetStorageVerification($allVerified ? MiningFleetStorageCheck::Verified : MiningFleetStorageCheck::Short, null, $containerName, $assetsReadAt, $lines);
    }

    private function nothingDelivered(?string $containerName): MiningFleetStorageVerification
    {
        return new MiningFleetStorageVerification(MiningFleetStorageCheck::NothingDelivered, null, $containerName, null, []);
    }

    /**
     * @param array<int, int>|null $expectedQuantities
     */
    private function unknown(MiningFleetStorageUnknownReason $reason, ?string $containerName, ?\DateTimeImmutable $assetsReadAt, ?array $expectedQuantities): MiningFleetStorageVerification
    {
        if ($expectedQuantities === null) {
            return new MiningFleetStorageVerification(MiningFleetStorageCheck::Unknown, $reason, $containerName, $assetsReadAt, []);
        }

        $typeIds = array_keys($expectedQuantities);
        sort($typeIds);
        $typeNames = $this->typeNameResolver->findNames($typeIds);
        $lines = array_map(
            static fn (int $typeId): MiningFleetStorageVerificationLine => new MiningFleetStorageVerificationLine(
                $typeId,
                $typeNames[$typeId] ?? sprintf('Type %d', $typeId),
                $expectedQuantities[$typeId],
                null,
                null,
                MiningFleetStorageCheck::Unknown,
            ),
            $typeIds,
        );
        usort($lines, static fn (MiningFleetStorageVerificationLine $left, MiningFleetStorageVerificationLine $right): int => $left->typeName <=> $right->typeName);

        return new MiningFleetStorageVerification(MiningFleetStorageCheck::Unknown, $reason, $containerName, $assetsReadAt, $lines);
    }
}
