<?php

declare(strict_types=1);

namespace App\ApiResource\Industry;

use ApiPlatform\Metadata\ApiProperty;

class StructureSearchListResource
{
    #[ApiProperty(identifier: true)]
    public string $id = 'search';

    /** @var StructureSearchResource[] */
    public array $structures = [];
}
