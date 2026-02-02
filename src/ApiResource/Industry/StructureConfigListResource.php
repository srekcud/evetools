<?php

declare(strict_types=1);

namespace App\ApiResource\Industry;

use ApiPlatform\Metadata\ApiProperty;

class StructureConfigListResource
{
    #[ApiProperty(identifier: true)]
    public string $id = 'list';

    /** @var StructureConfigResource[] */
    public array $structures = [];

    /** @var array<string, array<string, mixed>> */
    public array $rigOptions = [];
}
