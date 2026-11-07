<?php

declare(strict_types=1);

namespace App\State\Processor\Industry\Project;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Industry\Project\IndustryProjectTemplateResource;
use App\ApiResource\Input\Industry\Project\UpdateProjectTemplateInput;
use App\Entity\User;
use App\Repository\IndustryProjectRepository;
use App\Repository\IndustryProjectTemplateRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * @implements ProcessorInterface<UpdateProjectTemplateInput, IndustryProjectTemplateResource>
 */
class UpdateProjectTemplateProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly IndustryProjectTemplateRepository $templateRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly IndustryProjectRepository $projectRepository,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): IndustryProjectTemplateResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $template = $this->templateRepository->find(Uuid::fromString($uriVariables['id']));

        if ($template === null) {
            throw new NotFoundHttpException('Template not found');
        }

        if ($template->getOwner() !== $user) {
            throw new AccessDeniedHttpException('Only the template owner can perform this action');
        }

        assert($data instanceof UpdateProjectTemplateInput);

        if ($data->name !== null) {
            $template->setName($data->name);
        }

        if ($data->sharedWithCorporation !== null) {
            $template->setSharedWithCorporation($data->sharedWithCorporation);
        }

        $template->setUpdatedAt(new \DateTimeImmutable());
        $this->entityManager->flush();

        return IndustryProjectTemplateResource::fromTemplate($template, $user, $this->projectRepository->countByTemplate([$template])[(string) $template->getId()]);
    }
}
