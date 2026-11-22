<?php

declare(strict_types=1);

namespace App\Service\Industry\Project\Action;

use App\Constant\EveConstants;
use App\Entity\IndustryProjectBomLine;
use App\Entity\User;
use App\Enum\SourcingMarket;
use App\Repository\CachedStructureRepository;
use App\Repository\IndustryStructureConfigRepository;
use App\Repository\Sde\MapSolarSystemRepository;
use App\Repository\Sde\StaStationRepository;

final readonly class ActionPlaceResolver
{
    public function __construct(
        private StaStationRepository $stationRepository,
        private CachedStructureRepository $cachedStructureRepository,
        private IndustryStructureConfigRepository $structureConfigRepository,
        private MapSolarSystemRepository $solarSystemRepository,
    ) {
    }

    public function ofMarket(?SourcingMarket $market, User $viewer): ActionPlace
    {
        return match ($market) {
            null => ActionPlace::unknown(ActionPlace::UNKNOWN_MARKET_NOT_CHOSEN),
            SourcingMarket::Jita => $this->jita(),
            SourcingMarket::Structure => $this->marketStructureOf($viewer),
        };
    }

    public function ofStep(IndustryProjectBomLine $line, User $owner): ActionPlace
    {
        $plannedStructureId = $line->getPlannedStructureId();
        if (null === $plannedStructureId) {
            return ActionPlace::unknown(ActionPlace::UNKNOWN_STATION_UNRESOLVED);
        }

        foreach ($this->structureConfigRepository->findByUser($owner) as $configuration) {
            if ($configuration->getId()->toRfc4122() !== $plannedStructureId) {
                continue;
            }
            $locationId = $configuration->getLocationId();
            if (null === $locationId) {
                break;
            }
            $solarSystemId = $configuration->getSolarSystemId();

            return ActionPlace::station($locationId, $configuration->getName(), $solarSystemId, $this->solarSystemNameOf($solarSystemId));
        }

        return ActionPlace::unknown(ActionPlace::UNKNOWN_STATION_UNRESOLVED);
    }

    private function jita(): ActionPlace
    {
        $station = $this->stationRepository->findByStationId(EveConstants::JITA_STATION_ID);

        return ActionPlace::station(
            EveConstants::JITA_STATION_ID,
            $station?->getStationName(),
            EveConstants::JITA_SOLAR_SYSTEM_ID,
            $station?->getSolarSystem()->getSolarSystemName() ?? $this->solarSystemNameOf(EveConstants::JITA_SOLAR_SYSTEM_ID),
        );
    }

    private function marketStructureOf(User $viewer): ActionPlace
    {
        $structureId = $viewer->getEffectiveMarketStructureId();
        if (null === $structureId) {
            return ActionPlace::unknown(ActionPlace::UNKNOWN_MARKET_STRUCTURE_UNSET);
        }

        $solarSystemId = $this->cachedStructureRepository->findByStructureId($structureId)?->getSolarSystemId();

        return ActionPlace::station($structureId, $viewer->getEffectiveMarketStructureName(), $solarSystemId, $this->solarSystemNameOf($solarSystemId));
    }

    private function solarSystemNameOf(?int $solarSystemId): ?string
    {
        return null === $solarSystemId ? null : $this->solarSystemRepository->findBySolarSystemId($solarSystemId)?->getSolarSystemName();
    }
}
