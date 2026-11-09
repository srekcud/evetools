<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

use App\Entity\CachedAsset;
use App\Entity\CachedIndustryJob;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectJobMatch;
use App\Repository\CachedAssetRepository;
use App\Repository\CachedIndustryJobRepository;
use App\Service\Industry\IndustryCalculationService;

class ProjectJobLocationResolver
{
    private const string KIND_CONTAINER = 'container';
    private const string KIND_HANGAR = 'hangar';

    private const string STATUS_INSIDE = 'inside';
    private const string STATUS_OUTSIDE = 'outside';
    private const string STATUS_UNKNOWN = 'unknown';

    private const string REASON_LOCATION_MISSING = 'location_missing';
    private const string REASON_ASSETS_NOT_SYNCED = 'assets_not_synced';
    private const string REASON_LOCATION_UNRESOLVED = 'location_unresolved';

    public function __construct(
        private readonly CachedIndustryJobRepository $jobRepository,
        private readonly CachedAssetRepository $assetRepository,
        private readonly ProjectContainerService $containerService,
        private readonly IndustryCalculationService $calculationService,
    ) {
    }

    /**
     * @param list<IndustryProjectJobMatch> $matches
     *
     * @return array<int, array{blueprint: array<string, mixed>, output: array<string, mixed>}> indexed by ESI job id, absent when the cached job is gone
     */
    public function locationsOf(IndustryProject $project, array $matches): array
    {
        $jobs = $this->jobRepository->findByJobIds(array_map(
            static fn (IndustryProjectJobMatch $match): int => $match->getEsiJobId(),
            $matches,
        ));
        if ($jobs === []) {
            return [];
        }

        $scope = $this->containerService->containerScopeOf($project);
        $personalAssets = $this->assetRepository->findPersonalByItemIds($this->locationIdsOf($jobs));

        $locations = [];
        foreach ($jobs as $jobId => $job) {
            $locations[$jobId] = [
                'blueprint' => $this->sideOf($job->getBlueprintLocationId(), $job, $scope, $personalAssets),
                'output' => $this->sideOf($job->getOutputLocationId(), $job, $scope, $personalAssets),
            ];
        }

        return $locations;
    }

    /**
     * @param array<int, CachedIndustryJob> $jobs
     *
     * @return list<int>
     */
    private function locationIdsOf(array $jobs): array
    {
        $locationIds = [];
        foreach ($jobs as $job) {
            foreach ([$job->getBlueprintLocationId(), $job->getOutputLocationId()] as $locationId) {
                if ($locationId !== null) {
                    $locationIds[] = $locationId;
                }
            }
        }

        return array_values(array_unique($locationIds));
    }

    /**
     * @param array<int, CachedAsset> $personalAssets
     *
     * @return array<string, mixed>
     */
    private function sideOf(?int $locationId, CachedIndustryJob $job, ProjectContainerScope $scope, array $personalAssets): array
    {
        if ($locationId === null) {
            return $this->side(null, null, null, null, self::STATUS_UNKNOWN, self::REASON_LOCATION_MISSING);
        }

        $asset = $scope->visibleAssetsByItemId[$locationId] ?? $personalAssets[$locationId] ?? null;
        if ($asset !== null) {
            return $this->containerSide($locationId, $asset, $scope);
        }

        $facilityName = $this->calculationService->resolveFacilityName($locationId);
        if ($facilityName !== null) {
            return $this->side($locationId, self::KIND_HANGAR, $facilityName, null, self::STATUS_OUTSIDE, null);
        }

        return $this->side($locationId, null, null, null, self::STATUS_UNKNOWN, $this->unresolvedReason($job));
    }

    /**
     * @return array<string, mixed>
     */
    private function containerSide(int $locationId, CachedAsset $asset, ProjectContainerScope $scope): array
    {
        $name = $asset->getItemName() ?? $asset->getTypeName();
        if (!$scope->isKnown()) {
            return $this->side($locationId, self::KIND_CONTAINER, $name, $asset->getDivisionName(), self::STATUS_UNKNOWN, $scope->unknownReason);
        }

        $status = $scope->contains($locationId) ? self::STATUS_INSIDE : self::STATUS_OUTSIDE;

        return $this->side($locationId, self::KIND_CONTAINER, $name, $asset->getDivisionName(), $status, null);
    }

    private function unresolvedReason(CachedIndustryJob $job): string
    {
        $character = $job->getCharacter();
        $hasAssets = $this->assetRepository->hasCharacterAssets($character)
            || $this->assetRepository->hasCorporationAssets($character->getCorporationId());

        return $hasAssets ? self::REASON_LOCATION_UNRESOLVED : self::REASON_ASSETS_NOT_SYNCED;
    }

    /**
     * @return array<string, mixed>
     */
    private function side(?int $locationId, ?string $kind, ?string $name, ?string $divisionName, string $status, ?string $unknownReason): array
    {
        return [
            'locationId' => $locationId,
            'kind' => $kind,
            'name' => $name,
            'divisionName' => $divisionName,
            'status' => $status,
            'unknownReason' => $unknownReason,
        ];
    }
}
