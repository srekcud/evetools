<?php

declare(strict_types=1);

namespace App\ApiResource\ProfitTracker;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model;
use App\ApiResource\Input\EmptyInput;
use App\State\Processor\ProfitTracker\ComputeProfitProcessor;

#[ApiResource(
    shortName: 'ProfitCompute',
    description: 'Trigger profit matching computation',
    operations: [
        new Post(
            uriTemplate: '/profit-tracker/compute',
            processor: ComputeProfitProcessor::class,
            input: EmptyInput::class,
            openapi: new Model\Operation(summary: 'Trigger profit matching (async)'),
        ),
    ],
    security: "is_granted('ROLE_USER')",
)]
class ProfitComputeResource
{
    public bool $success = false;

    public string $message = '';
}
