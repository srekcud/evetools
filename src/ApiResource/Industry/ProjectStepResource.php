<?php

declare(strict_types=1);

namespace App\ApiResource\Industry;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\ApiResource\Input\Industry\CreateStepInput;
use App\ApiResource\Input\Industry\UpdateStepInput;
use App\State\Processor\Industry\CreateStepProcessor;
use App\State\Processor\Industry\DeleteStepProcessor;
use App\State\Processor\Industry\UpdateStepProcessor;
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
    ],
    security: "is_granted('ROLE_USER')",
)]
class ProjectStepResource
{
    #[ApiProperty(identifier: true)]
    public string $id;

    public int $blueprintTypeId;

    public int $productTypeId;

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

    public bool $inStock = false;

    public int $inStockQuantity = 0;

    public ?int $meLevel = null;

    public ?int $teLevel = null;

    public ?string $recommendedStructureName = null;

    public ?float $structureBonus = null;

    public ?float $structureTimeBonus = null;

    public ?int $timePerRun = null;

    // ESI job data
    public ?int $esiJobsTotalRuns = null;

    public ?float $esiJobCost = null;

    public ?string $esiJobStatus = null;

    public ?string $esiJobCharacterName = null;

    public ?int $esiJobsCount = null;

    public bool $manualJobData = false;
}
