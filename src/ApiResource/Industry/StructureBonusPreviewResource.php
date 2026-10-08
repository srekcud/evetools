<?php

declare(strict_types=1);

namespace App\ApiResource\Industry;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model;
use App\ApiResource\Input\Industry\StructureBonusPreviewInput;
use App\State\Processor\Industry\StructureBonusPreviewProcessor;

#[ApiResource(
    shortName: 'IndustryStructureBonusPreview',
    description: 'Bonuses of a structure configuration not saved yet',
    operations: [
        new Post(
            uriTemplate: '/industry/structures/bonus-preview',
            status: 200,
            processor: StructureBonusPreviewProcessor::class,
            input: StructureBonusPreviewInput::class,
            openapi: new Model\Operation(summary: 'Preview structure bonuses', description: 'Computes the bonuses of a structure configuration exactly as for a saved structure', tags: ['Industry - Structures']),
        ),
    ],
    security: "is_granted('ROLE_USER')",
)]
class StructureBonusPreviewResource
{
    public float $manufacturingMaterialBonus = 0.0;

    public float $reactionMaterialBonus = 0.0;

    public float $manufacturingTimeBonus = 0.0;

    public float $reactionTimeBonus = 0.0;
}
