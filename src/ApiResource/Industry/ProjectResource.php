<?php

declare(strict_types=1);

namespace App\ApiResource\Industry;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model;
use App\ApiResource\Input\EmptyInput;
use App\ApiResource\Input\Industry\ApplyStockInput;
use App\ApiResource\Input\Industry\CreateProjectInput;
use App\ApiResource\Input\Industry\UpdateProjectInput;
use App\State\Processor\Industry\AdaptStockProcessor;
use App\State\Processor\Industry\ApplyStockProcessor;
use App\State\Processor\Industry\CreateProjectProcessor;
use App\State\Processor\Industry\DeleteProjectProcessor;
use App\State\Processor\Industry\MatchJobsProcessor;
use App\State\Processor\Industry\RegenerateStepsProcessor;
use App\State\Processor\Industry\UpdateProjectProcessor;
use App\State\Provider\Industry\ProjectCollectionProvider;
use App\State\Provider\Industry\ProjectDeleteProvider;
use App\State\Provider\Industry\ProjectProvider;
use App\State\Provider\Industry\ShoppingListProvider;

#[ApiResource(
    shortName: 'IndustryProject',
    description: 'Industry manufacturing projects',
    operations: [
        new Get(
            uriTemplate: '/industry/projects',
            provider: ProjectCollectionProvider::class,
            output: ProjectListResource::class,
            openapi: new Model\Operation(summary: 'List projects', description: 'Returns all industry projects for the user'),
        ),
        new Get(
            uriTemplate: '/industry/projects/{id}',
            provider: ProjectProvider::class,
            openapi: new Model\Operation(summary: 'Get project details', description: 'Returns detailed project information with steps and tree'),
        ),
        new Post(
            uriTemplate: '/industry/projects',
            processor: CreateProjectProcessor::class,
            input: CreateProjectInput::class,
            openapi: new Model\Operation(summary: 'Create project', description: 'Creates a new industry project'),
        ),
        new Patch(
            uriTemplate: '/industry/projects/{id}',
            provider: ProjectProvider::class,
            processor: UpdateProjectProcessor::class,
            input: UpdateProjectInput::class,
            openapi: new Model\Operation(summary: 'Update project', description: 'Updates project properties'),
        ),
        new Delete(
            uriTemplate: '/industry/projects/{id}',
            provider: ProjectDeleteProvider::class,
            processor: DeleteProjectProcessor::class,
            openapi: new Model\Operation(summary: 'Delete project', description: 'Deletes an industry project'),
        ),
        new Get(
            uriTemplate: '/industry/projects/{id}/shopping-list',
            provider: ShoppingListProvider::class,
            output: ShoppingListResource::class,
            openapi: new Model\Operation(summary: 'Get shopping list', description: 'Returns materials needed with price comparison'),
        ),
        new Post(
            uriTemplate: '/industry/projects/{id}/regenerate-steps',
            processor: RegenerateStepsProcessor::class,
            input: EmptyInput::class,
            openapi: new Model\Operation(summary: 'Regenerate steps', description: 'Regenerates all project steps from scratch'),
        ),
        new Post(
            uriTemplate: '/industry/projects/{id}/match-jobs',
            processor: MatchJobsProcessor::class,
            input: EmptyInput::class,
            output: MatchJobsResultResource::class,
            openapi: new Model\Operation(summary: 'Match ESI jobs', description: 'Matches project steps with ESI industry jobs'),
        ),
        new Post(
            uriTemplate: '/industry/projects/{id}/apply-stock',
            processor: ApplyStockProcessor::class,
            input: ApplyStockInput::class,
            openapi: new Model\Operation(summary: 'Apply stock', description: 'Applies parsed inventory stock to project steps'),
        ),
        new Post(
            uriTemplate: '/industry/projects/{id}/adapt-stock',
            processor: AdaptStockProcessor::class,
            input: EmptyInput::class,
            openapi: new Model\Operation(summary: 'Adapt plan to stock', description: 'Recalculates step runs based on in-stock quantities'),
        ),
    ],
    security: "is_granted('ROLE_USER')",
)]
class ProjectResource
{
    #[ApiProperty(identifier: true)]
    public string $id;

    public int $productTypeId;

    /** Resolved dynamically from SDE */
    public string $productTypeName;

    public ?string $name = null;

    public string $displayName = '';

    public int $runs;

    public int $meLevel;

    public int $teLevel;

    public float $maxJobDurationDays;

    public string $status;

    public ?float $profit = null;

    public ?float $profitPercent = null;

    public ?float $bpoCost = null;

    public ?float $materialCost = null;

    public ?float $transportCost = null;

    public ?float $taxAmount = null;

    public ?float $sellPrice = null;

    public ?float $jobsCost = null;

    public ?float $totalCost = null;

    public ?string $notes = null;

    public bool $personalUse = false;

    public ?string $jobsStartDate = null;

    public ?string $completedAt = null;

    public string $createdAt;

    /** @var array<array{typeId: int, typeName: string, runs: int, meLevel: int|null, teLevel: int|null}> */
    public array $rootProducts = [];

    /** @var ProjectStepResource[] */
    public array $steps = [];

    /** @var array<string, mixed>|null */
    public ?array $tree = null;
}
