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
use ApiPlatform\OpenApi\Model;
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
            openapi: new Model\Operation(
                summary: 'List own escalations',
                tags: ['Revenue - Escalations'],
                parameters: [
                    new Model\Parameter(name: 'visibility', in: 'query', schema: ['type' => 'string']),
                    new Model\Parameter(name: 'saleStatus', in: 'query', schema: ['type' => 'string']),
                    new Model\Parameter(name: 'active', in: 'query', schema: ['type' => 'boolean']),
                ],
            ),
        ),
        new GetCollection(
            uriTemplate: '/escalations/corp',
            provider: EscalationCorpProvider::class,
            openapi: new Model\Operation(summary: 'List corporation escalations', tags: ['Revenue - Escalations']),
        ),
        new GetCollection(
            uriTemplate: '/escalations/public',
            provider: EscalationPublicProvider::class,
            openapi: new Model\Operation(summary: 'List public escalations (no auth required)', tags: ['Revenue - Escalations']),
        ),
        new Get(
            uriTemplate: '/escalations/{id}',
            provider: EscalationProvider::class,
            openapi: new Model\Operation(tags: ['Revenue - Escalations']),
        ),
        new Post(
            uriTemplate: '/escalations',
            input: CreateEscalationInput::class,
            processor: CreateEscalationProcessor::class,
            openapi: new Model\Operation(tags: ['Revenue - Escalations']),
        ),
        new Patch(
            uriTemplate: '/escalations/{id}',
            provider: EscalationProvider::class,
            processor: UpdateEscalationProcessor::class,
            openapi: new Model\Operation(
                summary: 'Update an escalation',
                tags: ['Revenue - Escalations'],
                requestBody: new Model\RequestBody(
                    content: new \ArrayObject([
                        'application/merge-patch+json' => [
                            'schema' => [
                                'type' => 'object',
                                'properties' => [
                                    'visibility' => ['type' => 'string', 'enum' => ['perso', 'corp', 'alliance', 'public']],
                                    'bmStatus' => ['type' => 'string', 'enum' => ['nouveau', 'bm']],
                                    'saleStatus' => ['type' => 'string', 'enum' => ['envente', 'vendu']],
                                    'price' => ['type' => 'integer'],
                                    'notes' => ['type' => 'string', 'nullable' => true],
                                ],
                            ],
                        ],
                    ]),
                ),
            ),
        ),
        new Delete(
            uriTemplate: '/escalations/{id}',
            provider: EscalationDeleteProvider::class,
            processor: DeleteEscalationProcessor::class,
            openapi: new Model\Operation(tags: ['Revenue - Escalations']),
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
