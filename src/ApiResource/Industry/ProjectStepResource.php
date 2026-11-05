<?php

declare(strict_types=1);

namespace App\ApiResource\Industry;

use ApiPlatform\Metadata\ApiProperty;

class ProjectStepResource
{
    #[ApiProperty(identifier: true)]
    public string $id;

    public int $blueprintTypeId;

    public int $productTypeId;

    /** Resolved dynamically from SDE */
    public ?string $productTypeName;

    public int $quantity;

    public int $runs;

    public int $depth;

    public string $activityType;

    public int $sortOrder;

    public ?string $splitGroupId = null;

    public ?int $splitIndex = null;

    public ?int $totalGroupRuns = null;

    public bool $purchased = false;

    public bool $runsFixed = false;

    public int $inStockQuantity = 0;

    public bool $inStock;

    public int $meLevel;

    public int $teLevel;

    public string $jobMatchMode = 'auto';

    /** @var string|null Structure config UUID */
    public ?string $structureConfigId = null;

    /** Resolved dynamically from structure config */
    public ?string $structureConfigName = null;

    /** Calculated dynamically */
    public ?float $structureMaterialBonus = null;

    /** Calculated dynamically */
    public ?float $structureTimeBonus = null;

    /** True when the favorite system holds no structure able to run the activity, so the best of all structures is used */
    public bool $favoriteSystemWithoutSuitableStructure;

    /** Calculated dynamically (seconds per run) */
    public ?int $timePerRun = null;

    /** Best character name for this step (based on skills) */
    public ?string $recommendedCharacterName = null;

    /** Type: 'suboptimal' (better structure available), 'unconfigured' (unknown facility), null */
    public ?string $facilityInfoType = null;

    /** Actual facility name from ESI job (for 'unconfigured' case) */
    public ?string $actualFacilityName = null;

    /** Best structure name (for 'suboptimal' case) */
    public ?string $bestStructureName = null;

    /** Best structure material bonus (for 'suboptimal' case) */
    public ?float $bestMaterialBonus = null;

    /** @var array<int, array<string, mixed>> Job matches from IndustryStepJobMatch entities */
    public array $jobMatches = [];

    /** Total job cost summed from matches */
    public ?float $jobsCost = null;

    /** @var array<int, array<string, mixed>> Purchases from IndustryStepPurchase entities */
    public array $purchases = [];

    /** Total purchase cost */
    public ?float $purchasesCost = null;

    /** Sum of purchased quantities for this step (computed from linked purchases) */
    public int $purchasedQuantity = 0;

    /** @var array<int, array<string, mixed>> All ESI jobs with same blueprint (informational, independent of matching) */
    public array $similarJobs = [];

    public bool $deletable;
}
