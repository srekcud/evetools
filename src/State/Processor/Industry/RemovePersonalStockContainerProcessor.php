<?php

declare(strict_types=1);

namespace App\State\Processor\Industry;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Industry\PersonalStockContainerResource;
use App\Entity\User;
use App\Repository\PersonalStockContainerRepository;
use App\Service\UuidOrNotFound;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProcessorInterface<PersonalStockContainerResource, void>
 */
final readonly class RemovePersonalStockContainerProcessor implements ProcessorInterface
{
    public function __construct(
        private Security $security,
        private PersonalStockContainerRepository $containerRepository,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): void
    {
        \assert($data instanceof PersonalStockContainerResource);

        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $declaration = $this->containerRepository->findOfUser($user, UuidOrNotFound::of($data->id, 'Personal stock container not found'));
        if (null === $declaration) {
            throw new NotFoundHttpException('Personal stock container not found');
        }

        $this->entityManager->remove($declaration);
        $this->entityManager->flush();
    }
}
