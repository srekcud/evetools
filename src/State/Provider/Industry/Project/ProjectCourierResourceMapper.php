<?php

declare(strict_types=1);

namespace App\State\Provider\Industry\Project;

use App\ApiResource\Industry\Project\ProjectCourierResource;
use App\Entity\IndustryProject;
use App\Service\Industry\Project\Courier\CourierCandidate;
use App\Service\Industry\Project\Courier\CourierContract;
use App\Service\Industry\Project\Courier\CourierLine;
use App\Service\Industry\Project\Courier\CourierLinkedContract;
use App\Service\Industry\Project\Courier\CourierOverview;
use App\Service\Industry\Project\Courier\CourierQuoteCalculator;

final readonly class ProjectCourierResourceMapper
{
    public function toResource(IndustryProject $project, CourierOverview $overview): ProjectCourierResource
    {
        $resource = new ProjectCourierResource();
        $resource->id = $project->getId()->toRfc4122();
        $resource->volumeCubicMeters = $overview->quote->volumeCubicMeters;
        $resource->collateral = $overview->quote->collateral;
        $resource->reward = $overview->quote->reward;
        $resource->jumps = $overview->quote->jumps;
        $resource->quoteStatus = $overview->quote->quoteStatus;
        $resource->missingVolumeTypeIds = $overview->quote->missingVolumeTypeIds;
        $resource->daysToComplete = CourierQuoteCalculator::DAYS_TO_COMPLETE;
        $resource->expirationDays = CourierQuoteCalculator::EXPIRATION_DAYS;
        $resource->originSolarSystemId = $overview->originSolarSystemId;
        $resource->originSolarSystemName = $overview->originSolarSystemName;
        $resource->workshopSolarSystemId = $overview->workshopSolarSystemId;
        $resource->workshopSolarSystemName = $overview->workshopSolarSystemName;
        $resource->canLink = $overview->canLink;
        $resource->contract = null === $overview->contract ? null : $this->linkedContractRow($overview->contract);
        $resource->lines = array_map($this->lineRow(...), $overview->lines);
        $resource->candidates = array_map($this->candidateRow(...), $overview->candidates);

        return $resource;
    }

    /**
     * @return array<string, mixed>
     */
    private function linkedContractRow(CourierLinkedContract $contract): array
    {
        return [
            'contractId' => $contract->contractId,
            'characterId' => $contract->characterId,
            'state' => $contract->state->value,
            'unknownReason' => $contract->unknownReason,
            'volume' => $contract->volume,
            'reward' => $contract->reward,
            'collateral' => $contract->collateral,
            'startLocationId' => $contract->startLocationId,
            'endLocationId' => $contract->endLocationId,
            'issuedAt' => $contract->issuedAt?->format('c'),
            'linkedAt' => $contract->linkedAt->format('c'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function lineRow(CourierLine $line): array
    {
        return [
            'bomLineId' => $line->bomLineId,
            'typeId' => $line->typeId,
            'purchasedQuantity' => $line->purchasedQuantity,
            'transportState' => $line->transportState->value,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function candidateRow(CourierCandidate $candidate): array
    {
        return $this->contractRow($candidate->contract) + ['matchesQuote' => $candidate->matchesQuote];
    }

    /**
     * @return array<string, mixed>
     */
    private function contractRow(CourierContract $contract): array
    {
        return [
            'contractId' => $contract->contractId,
            'characterId' => $contract->characterId,
            'state' => $contract->state->value,
            'volume' => $contract->volume,
            'reward' => $contract->reward,
            'collateral' => $contract->collateral,
            'startLocationId' => $contract->startLocationId,
            'endLocationId' => $contract->endLocationId,
            'issuedAt' => $contract->issuedAt->format('c'),
        ];
    }
}
