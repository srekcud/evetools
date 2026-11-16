<?php

declare(strict_types=1);

namespace App\ApiResource\Industry;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model;
use App\State\Provider\Industry\StructureListExportProvider;

#[ApiResource(
    shortName: 'IndustryStructureListExport',
    description: 'Production structure list of the user, ready to be imported on another account',
    operations: [
        new Get(
            uriTemplate: '/industry/structures/export',
            provider: StructureListExportProvider::class,
            openapi: new Model\Operation(summary: 'Export structures', description: 'Returns the structure list of the user as a portable document', tags: ['Industry - Structures']),
        ),
    ],
    security: "is_granted('ROLE_USER')",
)]
class StructureListExportResource
{
    public const string FORMAT = 'evetools.industry-structures';

    public const int VERSION = 1;

    #[ApiProperty(identifier: true)]
    public string $id = 'export';

    public string $format = self::FORMAT;

    public int $version = self::VERSION;

    /** @var list<array{name: string, locationId: ?int, solarSystemId: ?int, structureType: string, securityType: string, rigs: list<string>, facilityTaxRate: ?float}> */
    public array $structures = [];
}
