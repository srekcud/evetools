<?php

declare(strict_types=1);

namespace App\State\Provider\Industry\Project;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Industry\Project\IndustryProjectTemplateResource;
use App\Entity\IndustryProjectTemplate;
use App\Entity\User;
use App\Repository\IndustryProjectRepository;
use App\Repository\IndustryProjectTemplateRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<IndustryProjectTemplateResource>
 */
class ProjectTemplateCollectionProvider implements ProviderInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly IndustryProjectTemplateRepository $templateRepository,
        private readonly IndustryProjectRepository $projectRepository,
    ) {
    }

    /** @return list<IndustryProjectTemplateResource> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        return array_map(
            static fn (IndustryProjectTemplate $template): IndustryProjectTemplateResource => IndustryProjectTemplateResource::fromTemplate($template, $user, 0),
            $this->templateRepository->findVisibleTo($user),
        );
    }
}
