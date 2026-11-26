<?php

declare(strict_types=1);

namespace App\State\Processor\Planetary;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\User;
use App\Repository\PlanetaryPlanTemplateRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * @implements ProcessorInterface<mixed, void>
 */
final readonly class DeletePlanTemplateProcessor implements ProcessorInterface
{
    public function __construct(
        private Security $security,
        private PlanetaryPlanTemplateRepository $templateRepository,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): void
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $id = (string) $uriVariables['id'];

        if (!Uuid::isValid($id)) {
            throw new NotFoundHttpException('Plan template not found');
        }

        $template = $this->templateRepository->findOneByUserAndId($user, Uuid::fromString($id));

        if ($template === null) {
            throw new NotFoundHttpException('Plan template not found');
        }

        $this->entityManager->remove($template);
        $this->entityManager->flush();
    }
}
