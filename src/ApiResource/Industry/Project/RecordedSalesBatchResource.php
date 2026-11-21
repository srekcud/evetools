<?php

declare(strict_types=1);

namespace App\ApiResource\Industry\Project;

use ApiPlatform\Metadata\ApiProperty;

class RecordedSalesBatchResource
{
    #[ApiProperty(identifier: true)]
    public string $id = 'batch';

    /** @var list<ProjectSaleResource> */
    public array $recorded = [];

    /** @var list<array{transactionId: string, message: string}> */
    public array $failures = [];
}
