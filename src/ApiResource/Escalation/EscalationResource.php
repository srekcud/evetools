<?php

declare(strict_types=1);

namespace App\ApiResource\Escalation;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\ApiResource\Input\Escalation\CreateEscalationInput;
use App\State\Processor\Escalation\CreateEscalationProcessor;
use App\State\Processor\Escalation\DeleteEscalationProcessor;
use App\State\Processor\Escalation\UpdateEscalationProcessor;
use App\State\Provider\Escalation\EscalationCollectionProvider;
use App\State\Provider\Escalation\EscalationCorpProvider;
use App\State\Provider\Escalation\EscalationDeleteProvider;
use App\State\Provider\Escalation\EscalationProvider;
use App\State\Provider\Escalation\EscalationPublicProvider;

#[ApiResource(
    shortName: 'Escalation',
    description: 'DED escalation tracking',
    operations: [
        new GetCollection(
            uriTemplate: '/escalations',
            provider: EscalationCollectionProvider::class,
            openapiContext: [
                'summary' => 'List own escalations',
                'parameters' => [
                    ['name' => 'visibility', 'in' => 'query', 'type' => 'string', 'description' => 'Filter by visibility (perso, corp, public)'],
                    ['name' => 'saleStatus', 'in' => 'query', 'type' => 'string', 'description' => 'Filter by sale status (envente, vendu)'],
                    ['name' => 'active', 'in' => 'query', 'type' => 'boolean', 'description' => 'Only active escalations'],
                ],
            ],
        ),
        new GetCollection(
            uriTemplate: '/escalations/corp',
            provider: EscalationCorpProvider::class,
            openapiContext: ['summary' => 'List corporation escalations'],
        ),
        new GetCollection(
            uriTemplate: '/escalations/public',
            provider: EscalationPublicProvider::class,
            openapiContext: ['summary' => 'List public escalations (no auth required)'],
        ),
        new Get(
            uriTemplate: '/escalations/{id}',
            provider: EscalationProvider::class,
        ),
        new Post(
            uriTemplate: '/escalations',
            input: CreateEscalationInput::class,
            processor: CreateEscalationProcessor::class,
        ),
        new Patch(
            uriTemplate: '/escalations/{id}',
            provider: EscalationProvider::class,
            processor: UpdateEscalationProcessor::class,
            openapiContext: [
                'summary' => 'Update an escalation',
                'requestBody' => [
                    'content' => [
                        'application/merge-patch+json' => [
                            'schema' => [
                                'type' => 'object',
                                'properties' => [
                                    'visibility' => ['type' => 'string', 'enum' => ['perso', 'corp', 'public']],
                                    'bmStatus' => ['type' => 'string', 'enum' => ['nouveau', 'bm']],
                                    'saleStatus' => ['type' => 'string', 'enum' => ['envente', 'vendu']],
                                    'price' => ['type' => 'integer'],
                                    'notes' => ['type' => 'string', 'nullable' => true],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ),
        new Delete(
            uriTemplate: '/escalations/{id}',
            provider: EscalationDeleteProvider::class,
            processor: DeleteEscalationProcessor::class,
        ),
    ],
    security: "is_granted('ROLE_USER')",
)]
class EscalationResource
{
    #[ApiProperty(identifier: true)]
    public string $id;

    public int $characterId;

    public string $characterName;

    public string $type;

    public int $solarSystemId;

    public string $solarSystemName;

    public float $securityStatus;

    public int $price;

    public string $visibility;

    public string $bmStatus;

    public string $saleStatus;

    public ?string $notes = null;

    public int $corporationId;

    public string $expiresAt;

    public string $createdAt;

    public string $updatedAt;

    public bool $isOwner = true;
}
