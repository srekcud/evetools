<?php

declare(strict_types=1);

namespace App\ApiResource\Industry;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\OpenApi\Model;
use App\State\Provider\Industry\AvailableJobsProvider;

#[ApiResource(
    shortName: 'AvailableJob',
    description: 'ESI jobs available for linking to project steps',
    operations: [
        new GetCollection(
            uriTemplate: '/industry/projects/{id}/available-jobs',
            provider: AvailableJobsProvider::class,
            openapi: new Model\Operation(summary: 'Available ESI jobs', description: 'Lists ESI jobs matching the project blueprints, with link status'),
        ),
    ],
    security: "is_granted('ROLE_USER')",
)]
class AvailableJobResource
{
    public int $esiJobId;

    public int $blueprintTypeId;

    public int $productTypeId;

    public string $productTypeName;

    public int $runs;

    public float $cost;

    public string $status;

    public string $startDate;

    public string $endDate;

    public string $characterName;

    /** Step ID if already linked, null if available */
    public ?string $linkedToStepId = null;

    /** Step product name if linked */
    public ?string $linkedToStepName = null;

    /** Match ID if linked (for unlinking) */
    public ?string $matchId = null;
}
