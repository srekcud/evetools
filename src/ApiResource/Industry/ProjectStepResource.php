<?php

declare(strict_types=1);

namespace App\ApiResource\Industry;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model;
use App\ApiResource\Input\Industry\LinkJobInput;
use App\State\Processor\Industry\LinkJobProcessor;
use App\State\Processor\Industry\UnlinkJobProcessor;
use App\State\Provider\Industry\JobMatchDeleteProvider;

#[ApiResource(
    shortName: 'ProjectStep',
    description: 'Industry project step',
    operations: [
        new Post(
            uriTemplate: '/industry/projects/{id}/steps/{stepId}/link-job',
            uriVariables: [
                'id' => new Link(fromClass: ProjectResource::class),
                'stepId' => new Link(fromClass: ProjectStepResource::class),
            ],
            processor: LinkJobProcessor::class,
            input: LinkJobInput::class,
            openapi: new Model\Operation(summary: 'Link ESI job', description: 'Links an ESI job to this step', tags: ['Industry - Projects']),
        ),
        new Delete(
            uriTemplate: '/industry/step-job-matches/{id}',
            provider: JobMatchDeleteProvider::class,
            processor: UnlinkJobProcessor::class,
            openapi: new Model\Operation(summary: 'Unlink ESI job', description: 'Removes a job match from a step', tags: ['Industry - Projects']),
        ),
    ],
    security: "is_granted('ROLE_USER')",
)]
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
