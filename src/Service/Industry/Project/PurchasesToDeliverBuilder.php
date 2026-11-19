<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

use App\Constant\EveConstants;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;
use App\Repository\Sde\MapSolarSystemRepository;
use App\Service\Industry\Project\Courier\PurchaseOriginResolver;

final readonly class PurchasesToDeliverBuilder
{
    private const int UNKNOWN_ORIGIN_KEY = 0;

    public function __construct(
        private ProjectContainerFulfillment $containerFulfillment,
        private PurchaseOriginResolver $originResolver,
        private MapSolarSystemRepository $solarSystemRepository,
    ) {
    }

    public function build(IndustryProject $project): PurchasesToDeliver
    {
        $fulfillments = $this->containerFulfillment->of($project);
        $solarSystemNames = [];
        $purchaseCount = 0;
        $rows = [];

        foreach ($project->getBomItems() as $bomLine) {
            $stockLeft = $fulfillments->containerQuantityOf($bomLine);
            foreach ($this->quantityByOriginOf($bomLine) as $originKey => $purchasedQuantity) {
                ++$purchaseCount;
                $received = null === $stockLeft ? null : min($purchasedQuantity, $stockLeft);
                $stockLeft = null === $stockLeft ? null : $stockLeft - $received;
                $missingQuantity = null === $received ? null : $purchasedQuantity - $received;
                if (0 === $missingQuantity) {
                    continue;
                }
                $solarSystemId = self::UNKNOWN_ORIGIN_KEY === $originKey ? null : $originKey;
                if (null !== $solarSystemId && !\array_key_exists($solarSystemId, $solarSystemNames)) {
                    $solarSystemNames[$solarSystemId] = $this->solarSystemRepository->findBySolarSystemId($solarSystemId)?->getSolarSystemName();
                }
                $rows[] = new PurchaseToDeliver(
                    $bomLine->getId()->toRfc4122(),
                    $bomLine->getTypeId(),
                    $this->originKindOf($solarSystemId),
                    $solarSystemId,
                    null === $solarSystemId ? null : $solarSystemNames[$solarSystemId],
                    $purchasedQuantity,
                    $missingQuantity,
                    $this->deliveryStateOf($missingQuantity, $purchasedQuantity),
                );
            }
        }

        usort($rows, $this->compareRows(...));

        return new PurchasesToDeliver($fulfillments->state(), $purchaseCount, $rows);
    }

    /**
     * @return array<int, int> purchased quantity by solar system id, Jita first, unknown origin (key 0) last
     */
    private function quantityByOriginOf(IndustryProjectBomLine $bomLine): array
    {
        $quantityByOrigin = [];
        foreach ($bomLine->getPurchases() as $purchase) {
            if ($purchase->getQuantity() <= 0) {
                continue;
            }
            $originKey = $this->originResolver->solarSystemIdOf($purchase) ?? self::UNKNOWN_ORIGIN_KEY;
            $quantityByOrigin[$originKey] = ($quantityByOrigin[$originKey] ?? 0) + $purchase->getQuantity();
        }
        uksort($quantityByOrigin, fn (int $left, int $right): int => $this->creditRank($left) <=> $this->creditRank($right));

        return $quantityByOrigin;
    }

    /**
     * @return array{int, int}
     */
    private function creditRank(int $originKey): array
    {
        return match (true) {
            EveConstants::JITA_SOLAR_SYSTEM_ID === $originKey => [0, 0],
            self::UNKNOWN_ORIGIN_KEY === $originKey => [2, 0],
            default => [1, $originKey],
        };
    }

    private function originKindOf(?int $solarSystemId): PurchaseOriginKind
    {
        return match (true) {
            null === $solarSystemId => PurchaseOriginKind::Unknown,
            EveConstants::JITA_SOLAR_SYSTEM_ID === $solarSystemId => PurchaseOriginKind::Jita,
            default => PurchaseOriginKind::Other,
        };
    }

    private function deliveryStateOf(?int $missingQuantity, int $purchasedQuantity): PurchaseDeliveryState
    {
        return match (true) {
            null === $missingQuantity => PurchaseDeliveryState::Unknown,
            $missingQuantity === $purchasedQuantity => PurchaseDeliveryState::Missing,
            default => PurchaseDeliveryState::Partial,
        };
    }

    private function compareRows(PurchaseToDeliver $left, PurchaseToDeliver $right): int
    {
        return [$this->kindRank($left->originKind), $left->solarSystemName ?? '', $left->solarSystemId ?? 0, $left->typeId]
            <=> [$this->kindRank($right->originKind), $right->solarSystemName ?? '', $right->solarSystemId ?? 0, $right->typeId];
    }

    private function kindRank(PurchaseOriginKind $kind): int
    {
        return match ($kind) {
            PurchaseOriginKind::Jita => 0,
            PurchaseOriginKind::Other => 1,
            PurchaseOriginKind::Unknown => 2,
        };
    }
}
