<?php

declare(strict_types=1);

namespace App\ApiResource\Industry;

use ApiPlatform\Metadata\ApiProperty;

class ProjectListResource
{
    #[ApiProperty(identifier: true)]
    public string $id = 'list';

    /** @var ProjectResource[] */
    public array $projects = [];

    public float $totalProfit = 0.0;
}
