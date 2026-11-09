<?php

declare(strict_types=1);

namespace App\ApiResource\Industry\Project;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model;
use App\ApiResource\Input\Industry\Project\RecordSaleFromWalletTransactionInput;
use App\ApiResource\Input\Industry\Project\RecordSaleInput;
use App\ApiResource\Input\Industry\Project\UpdateSaleInput;
use App\Entity\IndustryProjectSale;
use App\Entity\User;
use App\Service\Industry\Project\PilotName;
use App\State\Processor\Industry\Project\DeleteSaleProcessor;
use App\State\Processor\Industry\Project\RecordSaleFromWalletTransactionProcessor;
use App\State\Processor\Industry\Project\RecordSaleProcessor;
use App\State\Processor\Industry\Project\UpdateSaleProcessor;
use App\State\Provider\Industry\Project\ProjectSaleCollectionProvider;
use App\State\Provider\Industry\Project\ProjectSaleItemProvider;

#[ApiResource(
    shortName: 'IndustryProjectSale',
    description: 'Sales recorded for a group industry project',
    operations: [
        new GetCollection(
            uriTemplate: '/industry/projects/{projectId}/sales',
            uriVariables: [
                'projectId' => new Link(fromClass: IndustryProjectResource::class),
            ],
            provider: ProjectSaleCollectionProvider::class,
            openapi: new Model\Operation(
                summary: 'List project sales',
                description: 'Returns all sales recorded for the project',
                tags: ['Group Industry - Sales'],
            ),
        ),
        new Post(
            uriTemplate: '/industry/projects/{projectId}/sales',
            uriVariables: [
                'projectId' => new Link(fromClass: IndustryProjectResource::class),
            ],
            processor: RecordSaleProcessor::class,
            input: RecordSaleInput::class,
            openapi: new Model\Operation(
                summary: 'Record a sale',
                description: 'Records a new sale for the project (admin/owner only)',
                tags: ['Group Industry - Sales'],
            ),
        ),
        new Post(
            uriTemplate: '/industry/projects/{projectId}/sales/from-wallet-transaction',
            uriVariables: [
                'projectId' => new Link(fromClass: IndustryProjectResource::class),
            ],
            processor: RecordSaleFromWalletTransactionProcessor::class,
            input: RecordSaleFromWalletTransactionInput::class,
            openapi: new Model\Operation(
                summary: 'Record a sale from a wallet transaction',
                description: 'Records a sale of the project from a sale of one of your wallet transactions (admin/owner only)',
                tags: ['Group Industry - Sales'],
            ),
        ),
        new Patch(
            uriTemplate: '/industry/projects/{projectId}/sales/{id}',
            uriVariables: [
                'projectId' => new Link(fromClass: IndustryProjectResource::class),
                'id' => new Link(fromClass: self::class),
            ],
            provider: ProjectSaleItemProvider::class,
            processor: UpdateSaleProcessor::class,
            input: UpdateSaleInput::class,
            openapi: new Model\Operation(
                summary: 'Correct a sale',
                description: 'Corrects a recorded sale of the project (admin/owner only)',
                tags: ['Group Industry - Sales'],
            ),
        ),
        new Delete(
            uriTemplate: '/industry/projects/{projectId}/sales/{id}',
            uriVariables: [
                'projectId' => new Link(fromClass: IndustryProjectResource::class),
                'id' => new Link(fromClass: self::class),
            ],
            provider: ProjectSaleItemProvider::class,
            processor: DeleteSaleProcessor::class,
            openapi: new Model\Operation(
                summary: 'Delete a sale',
                description: 'Deletes a recorded sale of the project (admin/owner only)',
                tags: ['Group Industry - Sales'],
            ),
        ),
    ],
    security: "is_granted('ROLE_USER')",
    paginationEnabled: false,
)]
class ProjectSaleResource
{
    #[ApiProperty(identifier: true)]
    public string $id;

    public int $typeId;

    public string $typeName;

    public int $quantity;

    public float $unitPrice;

    public float $totalPrice;

    public ?string $venue = null;

    public string $channel = 'market';

    public string $soldAt;

    public ?string $recordedByCharacterName = null;

    public ?int $sellerCharacterId = null;

    public ?string $sellerCharacterName = null;

    public bool $linkedToWalletTransaction = false;

    public string $createdAt;

    public static function fromSale(IndustryProjectSale $sale, User $viewer): self
    {
        $resource = new self();
        $resource->id = $sale->getId()->toRfc4122();
        $resource->typeId = $sale->getTypeId();
        $resource->typeName = $sale->getTypeName();
        $resource->quantity = $sale->getQuantity();
        $resource->unitPrice = $sale->getUnitPrice();
        $resource->totalPrice = $sale->getTotalPrice();
        $resource->venue = $sale->getVenue();
        $resource->channel = $sale->getChannel()->value;
        $resource->soldAt = $sale->getSoldAt()->format(\DateTimeInterface::ATOM);
        $resource->recordedByCharacterName = PilotName::of($sale->getRecordedBy());
        $seller = $sale->getSeller();
        if ($seller === null) {
            $resource->sellerCharacterId = null;
            $resource->sellerCharacterName = null;
        } elseif ($viewer->getCharacters()->contains($seller)) {
            $resource->sellerCharacterId = $seller->getEveCharacterId();
            $resource->sellerCharacterName = $seller->getName();
        } else {
            $sellerUser = $seller->getUser();
            $resource->sellerCharacterId = $sellerUser?->getMainCharacter()?->getEveCharacterId();
            $resource->sellerCharacterName = PilotName::of($sellerUser);
        }
        $resource->linkedToWalletTransaction = $sale->getWalletTransaction() !== null;
        $resource->createdAt = $sale->getCreatedAt()->format(\DateTimeInterface::ATOM);

        return $resource;
    }
}
