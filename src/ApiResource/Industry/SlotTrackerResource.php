<?php

declare(strict_types=1);

namespace App\ApiResource\Industry;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model;
use App\State\Provider\Industry\SlotTrackerProvider;

#[ApiResource(
    shortName: 'SlotTracker',
    description: 'Industry slot usage across all characters',
    operations: [
        new Get(
            uriTemplate: '/industry/slots',
            provider: SlotTrackerProvider::class,
            openapi: new Model\Operation(summary: 'Get slot tracker data', description: 'Returns slot usage, jobs, and timeline for all characters', tags: ['Industry - Slots']),
        ),
    ],
    security: "is_granted('ROLE_USER')",
    paginationEnabled: false,
)]
class SlotTrackerResource
{
    /** @var array<string, array{used: int, max: int, percent: float}> */
    public array $globalKpis = [];

    /** @var list<array<string, mixed>> */
    public array $characters = [];

    /** @var list<array<string, mixed>> */
    public array $timeline = [];

    public bool $skillsMayBeStale = false;
}
