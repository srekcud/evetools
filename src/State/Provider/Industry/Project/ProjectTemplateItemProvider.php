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
class ProjectTemplateItemProvider implements ProviderInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly IndustryProjectTemplateRepository $templateRepository,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): IndustryProjectTemplateResource
    {
        throw new \LogicException('not implemented');
    }
}
