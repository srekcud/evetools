<?php

declare(strict_types=1);

namespace App\ApiResource\Industry\Project;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\ApiResource\Input\Industry\Project\CreateProjectTemplateInput;
use App\ApiResource\Input\Industry\Project\UpdateProjectTemplateInput;
use App\Entity\IndustryProjectTemplate;
use App\Entity\User;
use App\State\Processor\Industry\Project\CreateProjectTemplateProcessor;
use App\State\Processor\Industry\Project\DeleteProjectTemplateProcessor;
use App\State\Processor\Industry\Project\UpdateProjectTemplateProcessor;
use App\State\Provider\Industry\Project\ProjectTemplateCollectionProvider;
use App\State\Provider\Industry\Project\ProjectTemplateItemProvider;

#[ApiResource(
    shortName: 'IndustryProjectTemplate',
    operations: [
        new GetCollection(
            uriTemplate: '/industry/project-templates',
            provider: ProjectTemplateCollectionProvider::class,
        ),
        new Post(
            uriTemplate: '/industry/project-templates',
            processor: CreateProjectTemplateProcessor::class,
            input: CreateProjectTemplateInput::class,
        ),
        new Patch(
            uriTemplate: '/industry/project-templates/{id<[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}>}',
            provider: ProjectTemplateItemProvider::class,
            processor: UpdateProjectTemplateProcessor::class,
            input: UpdateProjectTemplateInput::class,
        ),
        new Delete(
            uriTemplate: '/industry/project-templates/{id<[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}>}',
            provider: ProjectTemplateItemProvider::class,
            processor: DeleteProjectTemplateProcessor::class,
        ),
    ],
    security: "is_granted('ROLE_USER')",
    paginationEnabled: false,
)]
class IndustryProjectTemplateResource
{
    #[ApiProperty(identifier: true)]
    public string $id;

    public string $name = '';

    /** @var list<array{typeId: int, typeName: string, meLevel: int, teLevel: int, runs: int}> */
    public array $items = [];

    /** @var list<int> */
    public array $blacklistGroupIds = [];

    /** @var list<int> */
    public array $blacklistTypeIds = [];

    public bool $acceptsOutsideFavoriteSystem = false;

    public float $minimumOutsideSaving = 0.0;

    public bool $sharedWithCorporation = false;

    public ?string $ownerName = null;

    public bool $isOwn = false;

    public int $usageCount = 0;

    public string $createdAt;

    public static function fromTemplate(IndustryProjectTemplate $template, User $viewer, int $usageCount): self
    {
        $resource = new self();
        $resource->id = (string) $template->getId();
        $resource->name = $template->getName();
        $resource->items = $template->getItems();
        $resource->blacklistGroupIds = $template->getBlacklistGroupIds();
        $resource->blacklistTypeIds = $template->getBlacklistTypeIds();
        $resource->acceptsOutsideFavoriteSystem = $template->isAcceptsOutsideFavoriteSystem();
        $resource->minimumOutsideSaving = $template->getMinimumOutsideSaving();
        $resource->sharedWithCorporation = $template->isSharedWithCorporation();
        $resource->ownerName = $template->getOwner()->getMainCharacter()?->getName();
        $resource->isOwn = $template->getOwner() === $viewer;
        $resource->usageCount = $usageCount;
        $resource->createdAt = $template->getCreatedAt()->format(\DateTimeInterface::ATOM);

        return $resource;
    }
}
