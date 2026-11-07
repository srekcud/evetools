<?php

declare(strict_types=1);

namespace App\State\Provider\Industry\Project;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Industry\Project\IndustryProjectTemplateResource;
use App\Entity\User;
use App\Repository\IndustryProjectTemplateRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

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
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        foreach ($this->templateRepository->findVisibleTo($user) as $template) {
            if ((string) $template->getId() !== $uriVariables['id']) {
                continue;
            }

            if ($template->getOwner() !== $user) {
                throw new AccessDeniedHttpException('Only the template owner can perform this action');
            }

            return IndustryProjectTemplateResource::fromTemplate($template, $user);
        }

        throw new NotFoundHttpException('Template not found');
    }
}
