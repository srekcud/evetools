<?php

declare(strict_types=1);

namespace App\ApiResource\Industry;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use ApiPlatform\OpenApi\Model;
use App\ApiResource\Industry\Project\IndustryProjectResource;
use App\State\Provider\Industry\AvailableJobsProvider;

#[ApiResource(
    shortName: 'AvailableJob',
    description: 'ESI jobs available for linking to the job lines of the project BOM',
    operations: [
        new GetCollection(
            uriTemplate: '/industry/projects/{projectId}/available-jobs',
            uriVariables: [
                'projectId' => new Link(fromClass: IndustryProjectResource::class),
            ],
            provider: AvailableJobsProvider::class,
            openapi: new Model\Operation(summary: 'Available ESI jobs', description: 'Lists ESI jobs on the blueprints of the job lines, with link status: the jobs of every accepted participant for the owner, the own jobs of the user for any other accepted participant', tags: ['Industry - Projects']),
        ),
    ],
    security: "is_granted('ROLE_USER')",
)]
class AvailableJobResource
{
    public int $esiJobId;

    public int $blueprintTypeId;

    public int $productTypeId;

    public ?string $productTypeName;

    public int $runs;

    public ?float $cost;

    public string $status;

    public string $startDate;

    public string $endDate;

    public string $characterName;

    public ?string $linkedToBomLineId = null;

    public ?string $linkedToBomLineName = null;

    public ?string $matchId = null;
}
