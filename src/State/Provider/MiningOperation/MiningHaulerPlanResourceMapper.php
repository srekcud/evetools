<?php

declare(strict_types=1);

namespace App\State\Provider\MiningOperation;

use App\ApiResource\MiningOperation\MiningHaulerPlanResource;
use App\Service\MiningOperation\MiningHaulerPlanReport;

final readonly class MiningHaulerPlanResourceMapper
{
    private const string KNOWN_STATE = 'known';
    private const string UNKNOWN_STATE = 'unknown';

    public function toResource(MiningHaulerPlanReport $report): MiningHaulerPlanResource
    {
        $resource = new MiningHaulerPlanResource();
        $resource->operationId = $report->operationId;
        $resource->state = $report->isKnown() ? self::KNOWN_STATE : self::UNKNOWN_STATE;
        $resource->unknownReason = $report->unknownReason?->value;
        $resource->boostShipName = $report->boostShipName;
        $resource->haulerName = $report->haulerName;
        $resource->emptiedAt = $report->emptiedAt;
        $resource->originIsStart = $report->originIsStart;
        $resource->capacityM3 = $report->capacityM3;
        $resource->holdCapacityM3 = $report->holdCapacityM3;
        $resource->share = $report->share;
        $resource->inflowM3PerMinute = $report->inflowM3PerMinute;
        $resource->fillM3 = $report->fillM3;
        $resource->fillRatio = $report->fillRatio;
        $resource->fullInMinutes = $report->fullInMinutes;
        $resource->isFull = $report->isFull;
        $resource->transportM3PerMinute = $report->transportM3PerMinute;
        $resource->toMoveM3 = $report->toMoveM3;
        $resource->trips = $report->trips;
        $resource->siteEtaAt = $report->siteEtaAt;
        $resource->rawTypeIds = $report->rawTypeIds;
        $resource->contributorNames = $report->contributorNames;

        return $resource;
    }
}
