<?php

declare(strict_types=1);

namespace App\ApiResource\Industry;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model;
use App\ApiResource\Input\EmptyInput;
use App\State\Processor\Industry\MatchJobsProcessor;

#[ApiResource(
    shortName: 'IndustryProject',
    description: 'Industry manufacturing projects',
    operations: [
        new Post(
            uriTemplate: '/industry/projects/{id}/match-jobs',
            uriVariables: [
                'id' => new Link(fromClass: ProjectResource::class),
            ],
            processor: MatchJobsProcessor::class,
            input: EmptyInput::class,
            output: MatchJobsResultResource::class,
            openapi: new Model\Operation(summary: 'Match ESI jobs', description: 'Matches project steps with ESI industry jobs', tags: ['Industry - Projects']),
        ),
    ],
    security: "is_granted('ROLE_USER')",
    normalizationContext: ['skip_null_values' => false],
)]
class ProjectResource
{
    #[ApiProperty(identifier: true)]
    public string $id;

    public int $productTypeId;

    /** Resolved dynamically from SDE */
    public ?string $productTypeName;

    public ?string $name = null;

    public ?string $displayName = null;

    public int $runs;

    public int $meLevel;

    public int $teLevel;

    public bool $isT2 = false;

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

    public ?float $estimatedJobCost = null;

    public ?float $estimatedMaterialCost = null;

    public ?float $estimatedSellPrice = null;

    public ?string $estimatedSellPriceSource = null;

    public ?float $estimatedTaxAmount = null;

    public ?string $notes = null;

    public bool $personalUse = false;

    public ?string $jobsStartDate = null;

    public ?string $completedAt = null;

    public string $createdAt;

    /** @var array<array{typeId: int, typeName: string|null, runs: int, meLevel: int|null, teLevel: int|null}> */
    public array $rootProducts = [];

    /** @var ProjectStepResource[] */
    public array $steps = [];

    /** @var array<string, mixed>|null */
    public ?array $tree = null;

    /** @var array<array{typeId: int, reason: string}>|null */
    public ?array $failedProducts = null;

    /** @var array<array{typeId: int, typeName: string, quantity: int}>|null */
    public ?array $inventionMaterials = null;
}
