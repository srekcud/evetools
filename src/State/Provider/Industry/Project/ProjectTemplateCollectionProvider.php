<?php

declare(strict_types=1);

namespace App\State\Provider\Industry\Project;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Industry\Project\IndustryProjectTemplateResource;
use App\Repository\IndustryProjectTemplateRepository;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * @implements ProviderInterface<IndustryProjectTemplateResource>
 */
class ProjectTemplateCollectionProvider implements ProviderInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly IndustryProjectTemplateRepository $templateRepository,
    ) {
    }

    /** @return list<IndustryProjectTemplateResource> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        throw new \LogicException('not implemented');
    }
}
