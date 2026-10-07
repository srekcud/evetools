<?php

declare(strict_types=1);

namespace App\ApiResource\Industry;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\OpenApi\Model;
use App\State\Provider\Industry\BatchScanProvider;

#[ApiResource(
    shortName: 'IndustryBatchScan',
    description: 'Batch profit scan for all manufacturable products',
    operations: [
        new GetCollection(
            uriTemplate: '/industry/profit-scan',
            provider: BatchScanProvider::class,
            openapi: new Model\Operation(
                summary: 'Scan profit margins',
                description: 'Scans all manufacturable/reactable products and returns ranked profit data',
                tags: ['Industry - Scanner'],
                parameters: [
                    new Model\Parameter(name: 'category', in: 'query', required: false, schema: ['type' => 'string', 'default' => 'all']),
                    new Model\Parameter(name: 'minMargin', in: 'query', required: false, schema: ['type' => 'number']),
                    new Model\Parameter(name: 'minDailyVolume', in: 'query', required: false, schema: ['type' => 'number']),
                    new Model\Parameter(name: 'sellVenue', in: 'query', required: false, schema: ['type' => 'string', 'default' => 'jita']),
                    new Model\Parameter(name: 'structureId', in: 'query', required: false, schema: ['type' => 'integer']),
                    new Model\Parameter(name: 'solarSystemId', in: 'query', required: false, schema: ['type' => 'integer']),
                ],
            ),
        ),
    ],
    security: "is_granted('ROLE_USER')",
    paginationEnabled: false,
)]
class BatchScanResultResource
{
    public int $typeId;

    public string $typeName = '';

    public string $groupName = '';

    /** "T1", "T2", "Capital", "Reaction" */
    public string $categoryLabel = '';

    public ?float $marginPercent = null;

    public ?float $profitPerUnit = null;

    public float $dailyVolume = 0;

    public ?float $iskPerDay = null;

    public ?float $materialCost = null;

    public float $importCost = 0;

    public float $exportCost = 0;

    public float $sellPrice = 0;

    public int $meUsed = 0;

    /** "manufacturing" or "reaction" */
    public string $activityType = 'manufacturing';

    /** Whether this item's blueprint is a faction BPC (expensive, not inventable) */
    public bool $isFactionBlueprint = false;

    /** User-defined BPC cost per run, null if not set */
    public ?float $bpcCostPerRun = null;

    /** Whether at least one character has all required skills */
    public bool $hasAllSkills = true;

    /** Number of missing skills (best character) */
    public int $missingSkillCount = 0;

    /** Why the cost and margins are unknown ("missing_material_price"), null when they are known */
    public ?string $unknownReason = null;

    /** @var list<int> Material typeIds without a Jita price */
    public array $missingPriceTypeIds = [];
}
