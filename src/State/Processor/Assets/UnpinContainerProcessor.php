<?php

declare(strict_types=1);

namespace App\State\Processor\Assets;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Assets\PinnedContainerResource;
use App\Entity\User;
use App\Repository\PinnedContainerRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProcessorInterface<PinnedContainerResource, void>
 */
class UnpinContainerProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly EntityManagerInterface $entityManager,
        private readonly PinnedContainerRepository $pinRepository,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): void
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        \assert($data instanceof PinnedContainerResource);

        $pin = $data->itemId === null ? null : $this->pinRepository->findByUserAndItemId($user, $data->itemId);
        if ($pin === null) {
            throw new ConflictHttpException('This container is pinned by an active project');
        }

        $this->entityManager->remove($pin);
        $this->entityManager->flush();
    }
}
