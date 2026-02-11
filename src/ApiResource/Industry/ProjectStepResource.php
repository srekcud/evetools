<?php

declare(strict_types=1);

namespace App\ApiResource\Industry;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\ApiResource\Input\EmptyInput;
use App\ApiResource\Input\Industry\CreateStepInput;
use App\ApiResource\Input\Industry\LinkJobInput;
use App\ApiResource\Input\Industry\SplitStepInput;
use App\ApiResource\Input\Industry\UpdateStepInput;
use App\State\Processor\Industry\CreateStepProcessor;
use App\State\Processor\Industry\DeleteStepProcessor;
use App\State\Processor\Industry\LinkJobProcessor;
use App\State\Processor\Industry\MergeStepsProcessor;
use App\State\Processor\Industry\SplitStepProcessor;
use App\State\Processor\Industry\UnlinkJobProcessor;
use App\State\Processor\Industry\UpdateStepProcessor;
use App\State\Provider\Industry\JobMatchDeleteProvider;
use App\State\Provider\Industry\ProjectStepDeleteProvider;
use App\State\Provider\Industry\ProjectStepProvider;

#[ApiResource(
    shortName: 'ProjectStep',
    description: 'Industry project step',
    operations: [
        new Post(
            uriTemplate: '/industry/projects/{id}/steps',
            processor: CreateStepProcessor::class,
            input: CreateStepInput::class,
            openapiContext: [
                'summary' => 'Create step',
                'description' => 'Creates a new step in the project',
            ],
        ),
        new Patch(
            uriTemplate: '/industry/projects/{id}/steps/{stepId}',
            provider: ProjectStepProvider::class,
            processor: UpdateStepProcessor::class,
            input: UpdateStepInput::class,
            openapiContext: [
                'summary' => 'Update step',
                'description' => 'Updates step properties',
            ],
        ),
        new Delete(
            uriTemplate: '/industry/projects/{id}/steps/{stepId}',
            provider: ProjectStepDeleteProvider::class,
            processor: DeleteStepProcessor::class,
            openapiContext: [
                'summary' => 'Delete step',
                'description' => 'Deletes a step from the project',
            ],
        ),
        new Post(
            uriTemplate: '/industry/projects/{id}/steps/{stepId}/split',
            processor: SplitStepProcessor::class,
            input: SplitStepInput::class,
            openapiContext: [
                'summary' => 'Split step',
                'description' => 'Splits a step into N equal jobs',
            ],
        ),
        new Post(
            uriTemplate: '/industry/projects/{id}/steps/{stepId}/merge',
            processor: MergeStepsProcessor::class,
            input: EmptyInput::class,
            openapiContext: [
                'summary' => 'Merge split group',
                'description' => 'Merges all steps in a split group back into one',
            ],
        ),
        new Post(
            uriTemplate: '/industry/projects/{id}/steps/{stepId}/link-job',
            processor: LinkJobProcessor::class,
            input: LinkJobInput::class,
            openapiContext: [
                'summary' => 'Link ESI job',
                'description' => 'Links an ESI job to this step',
            ],
        ),
        new Delete(
            uriTemplate: '/industry/step-job-matches/{id}',
            provider: JobMatchDeleteProvider::class,
            processor: UnlinkJobProcessor::class,
            openapiContext: [
                'summary' => 'Unlink ESI job',
                'description' => 'Removes a job match from a step',
            ],
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
    public string $productTypeName;

    public int $quantity;

    public int $runs;

    public int $depth;

    public string $activityType;

    public int $sortOrder;

    public ?string $splitGroupId = null;

    public ?int $splitIndex = null;

    public ?int $totalGroupRuns = null;

    public bool $purchased = false;

    public int $inStockQuantity = 0;

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

    /** @var array Job matches from IndustryStepJobMatch entities */
    public array $jobMatches = [];

    /** Total job cost summed from matches */
    public ?float $jobsCost = null;

    /** @var array Purchases from IndustryStepPurchase entities */
    public array $purchases = [];

    /** Total purchase cost */
    public ?float $purchasesCost = null;

    /** Sum of purchased quantities for this step (computed from linked purchases) */
    public int $purchasedQuantity = 0;
}
