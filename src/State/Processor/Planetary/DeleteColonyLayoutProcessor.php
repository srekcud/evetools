<?php

declare(strict_types=1);

namespace App\State\Processor\Planetary;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\User;
use App\Repository\PlanetaryColonyLayoutRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * @implements ProcessorInterface<mixed, void>
 */
final readonly class DeleteColonyLayoutProcessor implements ProcessorInterface
{
    private const string NOT_FOUND_MESSAGE = 'Colony layout not found';

    public function __construct(
        private Security $security,
        private PlanetaryColonyLayoutRepository $layoutRepository,
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
            throw new NotFoundHttpException(self::NOT_FOUND_MESSAGE);
        }

        $layout = $this->layoutRepository->findOneByUserAndId($user, Uuid::fromString($id));

        if ($layout === null) {
            throw new NotFoundHttpException(self::NOT_FOUND_MESSAGE);
        }

        $this->entityManager->remove($layout);
        $this->entityManager->flush();
    }
}
