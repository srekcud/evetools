<?php

declare(strict_types=1);

namespace App\ApiResource\Industry;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Post;
use App\ApiResource\Input\Industry\CreatePurchaseInput;
use App\State\Processor\Industry\CreatePurchaseProcessor;
use App\State\Processor\Industry\DeletePurchaseProcessor;
use App\State\Provider\Industry\StepPurchaseDeleteProvider;

#[ApiResource(
    shortName: 'StepPurchase',
    description: 'Purchase linked to an industry project step',
    operations: [
        new Post(
            uriTemplate: '/industry/projects/{id}/steps/{stepId}/purchases',
            processor: CreatePurchaseProcessor::class,
            input: CreatePurchaseInput::class,
            openapiContext: [
                'summary' => 'Create purchase',
                'description' => 'Links a purchase (ESI wallet or manual) to a step',
            ],
        ),
        new Delete(
            uriTemplate: '/industry/projects/{id}/steps/{stepId}/purchases/{purchaseId}',
            provider: StepPurchaseDeleteProvider::class,
            processor: DeletePurchaseProcessor::class,
            openapiContext: [
                'summary' => 'Delete purchase',
                'description' => 'Removes a purchase link from a step',
            ],
        ),
    ],
    security: "is_granted('ROLE_USER')",
)]
class StepPurchaseResource
{
    #[ApiProperty(identifier: true)]
    public string $id;

    public string $stepId;

    public int $typeId;

    public string $typeName;

    public int $quantity;

    public float $unitPrice;

    public float $totalPrice;

    /** 'esi_wallet' or 'manual' */
    public string $source;

    /** Wallet transaction ID if source is esi_wallet */
    public ?string $transactionId = null;

    public string $createdAt;
}
