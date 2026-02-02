<?php

declare(strict_types=1);

namespace App\State\Processor\Industry;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\User;
use App\Repository\IndustryStructureConfigRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * @implements ProcessorInterface<mixed, void>
 */
class DeleteStructureProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly IndustryStructureConfigRepository $structureConfigRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): void
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $structure = $this->structureConfigRepository->find(Uuid::fromString($uriVariables['id']));

        if ($structure === null || $structure->getUser() !== $user) {
            throw new NotFoundHttpException('Structure not found');
        }

        // If this is a corporation structure, soft-delete
        if ($structure->isCorporationStructure() && $structure->getLocationId() !== null) {
            $structure->setIsDeleted(true);
            $this->entityManager->flush();

            return;
        }

        $this->entityManager->remove($structure);
        $this->entityManager->flush();
    }
}
