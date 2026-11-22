<?php

declare(strict_types=1);

namespace App\Service\Industry\Project\Haul;

use App\Constant\EveConstants;
use App\Enum\StructureAccessStatus;
use App\Service\Industry\Project\PurchaseOriginKind;
use App\Service\Industry\Project\PurchaseToDeliver;
use App\Service\Industry\Project\StationQuantity;
use App\Service\Industry\Project\StationQuantityCalculator;
use App\Service\Industry\Project\Transfer\StationTransferLineState;
use App\Service\Industry\Project\Transfer\TransferLegState;
use App\Service\Industry\Project\TypeStationQuantities;

final readonly class HaulLegsBuilder
{
    private const int VOLUME_PRECISION = 6;
    private const int RANK_PLAN_STATION = 0;
    private const int RANK_PURCHASE = 1;
    private const int RANK_OTHER_STATION = 2;
    private const string REASON_PURCHASE_ORIGIN_UNKNOWN = 'purchase_origin_unknown';
    private const string REASON_PURCHASE_DELIVERY_UNKNOWN = 'purchase_delivery_unknown';

    public function build(HaulReadings $readings): HaulLegs
    {
        $stockUnknownReason = $readings->personalStockUnknownReason ?? $readings->personalStock?->unknownReason();
        $purchasesByTypeId = $this->purchasesByTypeId($readings->purchasesToDeliver);

        $unreadable = [];
        $demands = [];
        foreach ($readings->typeStationQuantities as $type) {
            if (null === $type->stations) {
                $unreadable[] = new HaulUnreadable($type->typeId, $type->typeName, null, $type->unknownReason ?? throw new \LogicException(\sprintf('The unavailable plan of type %d has no reason.', $type->typeId)));

                continue;
            }

            $hasDemand = false;
            foreach ($type->stations as $station) {
                if (null === $station->toBring) {
                    $unreadable[] = new HaulUnreadable($type->typeId, $type->typeName, $station->locationId, $station->unknownReason ?? throw new \LogicException(\sprintf('The unreadable station of type %d has no reason.', $type->typeId)));
                } elseif ($station->toBring > 0 && null !== $station->locationId) {
                    $hasDemand = true;
                    $demands[] = [$type, $station->locationId, $station->toBring];
                }
            }

            if ($hasDemand && $this->hasPurchaseOfUnknownOrigin($purchasesByTypeId[$type->typeId] ?? [])) {
                $unreadable[] = new HaulUnreadable($type->typeId, $type->typeName, null, self::REASON_PURCHASE_ORIGIN_UNKNOWN);
            }

            if ($hasDemand && $this->hasPurchaseOfUnknownDelivery($purchasesByTypeId[$type->typeId] ?? [])) {
                $unreadable[] = new HaulUnreadable($type->typeId, $type->typeName, null, self::REASON_PURCHASE_DELIVERY_UNKNOWN);
            }
        }

        usort($demands, fn (array $left, array $right): int => [$this->rankOf($readings, $left[1]), $left[0]->typeName, $left[0]->typeId]
            <=> [$this->rankOf($readings, $right[1]), $right[0]->typeName, $right[0]->typeId]);

        $drafts = [];
        $suppliesByTypeId = [];
        foreach ($demands as [$type, $arrivalLocationId, $toBring]) {
            $suppliesByTypeId[$type->typeId] ??= $this->suppliesOf($readings, $type, $purchasesByTypeId[$type->typeId] ?? [], null === $stockUnknownReason);
            $candidates = array_values(array_filter(
                $suppliesByTypeId[$type->typeId],
                static fn (HaulSupply $supply): bool => $supply->locationId !== $arrivalLocationId,
            ));
            foreach ($this->takesOf($candidates, $toBring) as [$supply, $quantity]) {
                $draft = $this->draftOf($drafts, $readings, $supply->departure, $supply->departureRank, $arrivalLocationId);
                foreach ($supply->take($quantity) as [$state, $takenQuantity]) {
                    $draft->add(
                        $type->typeId,
                        $type->typeName,
                        $state,
                        $supply->source,
                        $takenQuantity,
                        HaulLineState::InProduction === $state && null !== $supply->locationId ? $readings->productionEnd[$supply->locationId][$type->typeId] ?? null : null,
                    );
                }
            }
        }

        $this->addInTransitLines($drafts, $readings);

        return new HaulLegs($this->legsOf($drafts, $readings), $unreadable, $stockUnknownReason);
    }

    /**
     * @param list<PurchaseToDeliver> $purchases
     *
     * @return array<int, array<int, PurchaseToDeliver>>
     */
    private function purchasesByTypeId(array $purchases): array
    {
        $purchasesByTypeId = [];
        foreach ($purchases as $index => $purchase) {
            $purchasesByTypeId[$purchase->typeId][$index] = $purchase;
        }

        return $purchasesByTypeId;
    }

    /**
     * @param array<int, PurchaseToDeliver> $purchases
     */
    private function hasPurchaseOfUnknownOrigin(array $purchases): bool
    {
        foreach ($purchases as $purchase) {
            if (PurchaseOriginKind::Unknown === $purchase->originKind) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, PurchaseToDeliver> $purchases
     */
    private function hasPurchaseOfUnknownDelivery(array $purchases): bool
    {
        foreach ($purchases as $purchase) {
            if (PurchaseOriginKind::Unknown !== $purchase->originKind && null === $purchase->missingQuantity) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, PurchaseToDeliver> $purchases
     *
     * @return list<HaulSupply>
     */
    private function suppliesOf(HaulReadings $readings, TypeStationQuantities $type, array $purchases, bool $personalStockReadable): array
    {
        $supplies = [];
        foreach ($purchases as $index => $purchase) {
            if (PurchaseOriginKind::Unknown === $purchase->originKind || null === $purchase->missingQuantity || $purchase->missingQuantity <= 0) {
                continue;
            }
            $supplies[] = new HaulSupply(
                null,
                new HaulPlace(null, null, $purchase->solarSystemId, $purchase->solarSystemName),
                [self::RANK_PURCHASE, $index],
                HaulLineSource::Purchase,
                $purchase->missingQuantity,
                0,
            );
        }

        foreach ($type->stations ?? [] as $station) {
            $supply = $this->projectSupplyOf($readings, $station);
            if (null !== $supply && $supply->available() > 0) {
                $supplies[] = $supply;
            }
        }

        if ($personalStockReadable && null !== $readings->personalStock) {
            foreach ($readings->personalStock->stationsOf($type->typeId) as $location) {
                $supplies[] = new HaulSupply(
                    $location['locationId'],
                    $this->placeOf($readings, $location['locationId']),
                    $this->rankOf($readings, $location['locationId']),
                    HaulLineSource::PersonalStock,
                    $location['quantity'],
                    0,
                );
            }
        }

        return $supplies;
    }

    private function projectSupplyOf(HaulReadings $readings, StationQuantity $station): ?HaulSupply
    {
        if (null === $station->locationId || null === $station->out || null === $station->toBring || null === $station->inTransit) {
            return null;
        }

        return new HaulSupply(
            $station->locationId,
            $this->placeOf($readings, $station->locationId),
            $this->rankOf($readings, $station->locationId),
            HaulLineSource::Project,
            $station->out,
            max(0, $station->inProduction - $station->toBring - $station->inTransit),
        );
    }

    /**
     * @param list<HaulSupply> $candidates
     *
     * @return list<array{HaulSupply, int}>
     */
    private function takesOf(array $candidates, int $toBring): array
    {
        foreach ($candidates as $candidate) {
            if ($candidate->available() >= $toBring) {
                return [[$candidate, $toBring]];
            }
        }

        $takes = [];
        $remaining = $toBring;
        foreach ($candidates as $candidate) {
            $quantity = min($candidate->available(), $remaining);
            if ($quantity > 0) {
                $takes[] = [$candidate, $quantity];
                $remaining -= $quantity;
            }
        }

        return $takes;
    }

    /**
     * @param array<string, HaulLegDraft> $drafts
     * @param array{int, int} $departureRank
     */
    private function draftOf(array &$drafts, HaulReadings $readings, HaulPlace $departure, array $departureRank, int $arrivalLocationId): HaulLegDraft
    {
        $key = (null === $departure->locationId ? 's'.$departure->solarSystemId : (string) $departure->locationId).'>'.$arrivalLocationId;

        return $drafts[$key] ??= new HaulLegDraft(
            $key,
            $departure,
            $this->placeOf($readings, $arrivalLocationId),
            $this->rankOf($readings, $arrivalLocationId),
            $departureRank,
        );
    }

    /**
     * @param array<string, HaulLegDraft> $drafts
     */
    private function addInTransitLines(array &$drafts, HaulReadings $readings): void
    {
        $legsByRouteId = [];
        foreach ($readings->transferLegStates as $legState) {
            $legsByRouteId[$legState->routeId][$legState->legIndex] = $legState;
        }

        foreach ($legsByRouteId as $routeId => $legs) {
            ksort($legs);
            $firstLeg = $legs[array_key_first($legs)];
            $lastLeg = $legs[array_key_last($legs)];
            if (null === $lastLeg->toLocationId || !isset($readings->routeDepartureLocationIds[$routeId])) {
                continue;
            }

            $departureLocationId = $readings->routeDepartureLocationIds[$routeId];
            foreach ($lastLeg->lines as $lastLine) {
                $quantity = $this->inTransitQuantityOf($lastLine, $this->departedOf($firstLeg, $lastLine->typeId));
                if (0 === $quantity) {
                    continue;
                }
                $this->draftOf($drafts, $readings, $this->placeOf($readings, $departureLocationId), $this->rankOf($readings, $departureLocationId), $lastLeg->toLocationId)
                    ->add($lastLine->typeId, $lastLine->typeName, HaulLineState::InTransit, HaulLineSource::Transfer, $quantity, null);
            }
        }
    }

    private function departedOf(TransferLegState $firstLeg, int $typeId): ?int
    {
        foreach ($firstLeg->lines as $line) {
            if ($line->typeId === $typeId) {
                return $line->departed;
            }
        }

        throw new \LogicException(\sprintf('The first leg of route %s has no line for type %d.', $firstLeg->routeId, $typeId));
    }

    private function inTransitQuantityOf(StationTransferLineState $lastLine, ?int $departed): ?int
    {
        if ($lastLine->quantity === $lastLine->arrived) {
            return 0;
        }

        if (null === $departed || null === $lastLine->arrived) {
            return null;
        }

        return max(0, $departed - $lastLine->arrived);
    }

    /**
     * @param array<string, HaulLegDraft> $drafts
     *
     * @return list<HaulLeg>
     */
    private function legsOf(array $drafts, HaulReadings $readings): array
    {
        $drafts = array_values($drafts);
        usort($drafts, static fn (HaulLegDraft $left, HaulLegDraft $right): int => [$left->arrivalRank, $left->departureRank] <=> [$right->arrivalRank, $right->departureRank]);

        $legs = [];
        foreach ($drafts as $draft) {
            $carrierCharacterIds = [];
            $everyCharacterDenied = true;
            foreach ($readings->viewerCharacterIds as $characterId) {
                $statuses = [$this->statusAt($readings, $characterId, $draft->departure), $this->statusAt($readings, $characterId, $draft->arrival)];
                if (!\in_array(StructureAccessStatus::Denied, $statuses, true)) {
                    $everyCharacterDenied = false;
                }
                if ([StructureAccessStatus::Granted, StructureAccessStatus::Granted] === $statuses) {
                    $carrierCharacterIds[] = $characterId;
                }
            }
            if ($everyCharacterDenied) {
                continue;
            }

            $lines = $this->linesOf($draft, $readings);
            $legs[] = new HaulLeg(
                $draft->key,
                $draft->departure,
                $draft->arrival,
                [] === $carrierCharacterIds ? StructureAccessStatus::Unknown : StructureAccessStatus::Granted,
                $carrierCharacterIds,
                $this->legVolumeOf($lines),
                $lines,
            );
        }

        return $legs;
    }

    /**
     * @return list<HaulLine>
     */
    private function linesOf(HaulLegDraft $draft, HaulReadings $readings): array
    {
        $lines = $draft->lines();
        usort($lines, static fn (array $left, array $right): int => [self::stateRank($left['state']), $left['typeName'], $left['typeId']]
            <=> [self::stateRank($right['state']), $right['typeName'], $right['typeId']]);

        return array_map(function (array $line) use ($readings): HaulLine {
            $unitVolume = $readings->unitVolumes[$line['typeId']] ?? null;

            return new HaulLine(
                $line['typeId'],
                $line['typeName'],
                $line['quantity'],
                $line['state'],
                $line['source'],
                $line['readyAt'],
                $unitVolume,
                null === $line['quantity'] || null === $unitVolume ? null : round($line['quantity'] * $unitVolume, self::VOLUME_PRECISION),
                null === $line['quantity'] ? StationQuantityCalculator::REASON_TRANSFER_UNREADABLE : null,
            );
        }, $lines);
    }

    /**
     * @param list<HaulLine> $lines
     */
    private function legVolumeOf(array $lines): ?float
    {
        $volume = 0.0;
        foreach ($lines as $line) {
            if (null === $line->volume) {
                return null;
            }
            $volume += $line->volume;
        }

        return round($volume, self::VOLUME_PRECISION);
    }

    private static function stateRank(HaulLineState $state): int
    {
        return match ($state) {
            HaulLineState::Ready => 0,
            HaulLineState::InProduction => 1,
            HaulLineState::InTransit => 2,
        };
    }

    private function statusAt(HaulReadings $readings, int $characterId, HaulPlace $place): StructureAccessStatus
    {
        if (null === $place->locationId) {
            return EveConstants::JITA_SOLAR_SYSTEM_ID === $place->solarSystemId ? StructureAccessStatus::Granted : StructureAccessStatus::Unknown;
        }

        return $readings->access[$characterId][$place->locationId] ?? StructureAccessStatus::Unknown;
    }

    private function placeOf(HaulReadings $readings, int $locationId): HaulPlace
    {
        return $readings->places[$locationId] ?? new HaulPlace($locationId, null, null, null);
    }

    /**
     * @return array{int, int}
     */
    private function rankOf(HaulReadings $readings, int $locationId): array
    {
        $planIndex = array_search($locationId, $readings->planLocationIds, true);

        return false === $planIndex ? [self::RANK_OTHER_STATION, $locationId] : [self::RANK_PLAN_STATION, $planIndex];
    }
}
