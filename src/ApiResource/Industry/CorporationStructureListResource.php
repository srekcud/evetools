<?php

declare(strict_types=1);

namespace App\ApiResource\Industry;

use ApiPlatform\Metadata\ApiProperty;

class CorporationStructureListResource
{
    #[ApiProperty(identifier: true)]
    public string $id = 'list';

    /** @var CorporationStructureResource[] */
    public array $structures = [];
}
