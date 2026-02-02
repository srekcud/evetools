<?php

declare(strict_types=1);

namespace App\ApiResource\Industry;

use ApiPlatform\Metadata\ApiProperty;

class ProductSearchListResource
{
    #[ApiProperty(identifier: true)]
    public string $id = 'search';

    /** @var ProductSearchResource[] */
    public array $results = [];
}
