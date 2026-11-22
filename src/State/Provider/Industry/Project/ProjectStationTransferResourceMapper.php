<?php

declare(strict_types=1);

namespace App\State\Provider\Industry\Project;

use App\ApiResource\Industry\Project\ProjectStationTransferResource;
use App\Entity\IndustryProject;
use App\Repository\Sde\MapSolarSystemRepository;
use App\Service\Industry\Project\PilotName;
use App\Service\Industry\Project\ProjectContainerStations;
use App\Service\Industry\Project\Transfer\StationTransferLineState;
use App\Service\Industry\Project\Transfer\StationTransferReading;
use App\Dto\Transfer\TransferEndpoint;

final readonly class ProjectStationTransferResourceMapper
{
    public function __construct(
        private MapSolarSystemRepository $solarSystemRepository,
        private ProjectContainerStations $containerStations,
    ) {
    }

    /**
     * @param list<StationTransferReading> $readings
     *
     * @return list<ProjectStationTransferResource>
     */
    public function toResources(IndustryProject $project, array $readings): array
    {
        $stationNameByLocationId = [];
        foreach ($this->containerStations->of($project) as $station) {
            if (null !== $station->locationId) {
                $stationNameByLocationId[$station->locationId] = $station->structureName;
            }
        }

        $solarSystemNameById = [];

        return array_map(
            function (StationTransferReading $reading) use ($stationNameByLocationId, &$solarSystemNameById): ProjectStationTransferResource {
                $leg = $reading->leg;
                $resource = new ProjectStationTransferResource();
                $resource->id = $leg->getId()->toRfc4122();
                $resource->routeId = $leg->getRouteId()->toRfc4122();
                $resource->legIndex = $leg->getLegIndex();
                $resource->legCount = $reading->legCount;
                $resource->from = $this->placeOf($leg->getFrom(), $stationNameByLocationId, $solarSystemNameById);
                $resource->to = $this->placeOf($leg->getTo(), $stationNameByLocationId, $solarSystemNameById);
                $resource->carrier = [
                    'kind' => $leg->getCarrierKind()->value,
                    'characterId' => $leg->getCarrierCharacterId(),
                    'contractId' => $leg->getContractId(),
                ];
                $resource->declaredBy = PilotName::of($leg->getDeclaredBy());
                $resource->declaredAt = $leg->getDeclaredAt()->format(\DATE_ATOM);
                $resource->expectedArrivalAt = $reading->state->expectedArrivalAt->format(\DATE_ATOM);
                $resource->arrivedAt = $leg->getArrivedAt()?->format(\DATE_ATOM);
                $resource->state = $reading->state->state->value;
                $resource->volume = $reading->state->volume;
                $resource->unknownReason = $reading->state->unknownReason;
                $resource->lines = array_map(
                    static fn (StationTransferLineState $line): array => [
                        'typeId' => $line->typeId,
                        'typeName' => $line->typeName,
                        'quantity' => $line->quantity,
                        'volume' => $line->volume,
                        'departed' => $line->departed,
                        'arrived' => $line->arrived,
                        'inTransit' => $line->inTransit,
                        'departureSource' => $line->departureSource,
                        'arrivalSource' => $line->arrivalSource,
                        'unknownReason' => $line->unknownReason,
                    ],
                    $reading->state->lines,
                );

                return $resource;
            },
            $readings,
        );
    }

    /**
     * @param array<int, string> $stationNameByLocationId
     * @param array<int, string|null> $solarSystemNameById
     *
     * @return array{solarSystemId: int, solarSystemName: ?string, locationId: ?int, stationName: ?string}
     */
    private function placeOf(TransferEndpoint $endpoint, array $stationNameByLocationId, array &$solarSystemNameById): array
    {
        if (!\array_key_exists($endpoint->solarSystemId, $solarSystemNameById)) {
            $solarSystemNameById[$endpoint->solarSystemId] = $this->solarSystemRepository->findBySolarSystemId($endpoint->solarSystemId)?->getSolarSystemName();
        }

        return [
            'solarSystemId' => $endpoint->solarSystemId,
            'solarSystemName' => $solarSystemNameById[$endpoint->solarSystemId],
            'locationId' => $endpoint->locationId,
            'stationName' => null === $endpoint->locationId ? null : ($stationNameByLocationId[$endpoint->locationId] ?? null),
        ];
    }
}
