<?php

declare(strict_types=1);

namespace App\ApiResource\Industry;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model;
use App\ApiResource\Input\Industry\ImportStructureListInput;
use App\State\Processor\Industry\ImportStructureListProcessor;

#[ApiResource(
    shortName: 'IndustryStructureListImport',
    description: 'Outcome of an import of a production structure list',
    operations: [
        new Post(
            uriTemplate: '/industry/structures/import',
            status: 200,
            processor: ImportStructureListProcessor::class,
            input: ImportStructureListInput::class,
            openapi: new Model\Operation(summary: 'Import structures', description: 'Creates or updates the structures of an exported list, or only previews the outcome with dryRun', tags: ['Industry - Structures']),
        ),
    ],
    security: "is_granted('ROLE_USER')",
)]
class StructureListImportResource
{
    public const string STATUS_CREATE = 'create';

    public const string STATUS_UPDATE = 'update';

    public const string STATUS_SKIP = 'skip';

    public const string STATUS_INVALID = 'invalid';

    #[ApiProperty(identifier: true)]
    public string $id = 'import';

    public bool $applied = false;

    /** @var list<array{index: int, name: string, locationId: ?int, status: string, errors: list<string>}> */
    public array $entries = [];
}
