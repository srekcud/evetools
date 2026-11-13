<?php

declare(strict_types=1);

namespace App\State\Processor\Assets;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Assets\PinnedContainerResource;
use App\ApiResource\Input\Assets\PinContainerInput;
use App\Entity\PinnedContainer;
use App\Entity\User;
use App\Repository\PinnedContainerRepository;
use App\Service\Assets\PinnedContainerCatalog;
use App\State\Provider\Assets\PinnedContainerResourceMapper;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProcessorInterface<PinContainerInput, PinnedContainerResource>
 */
class PinContainerProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly EntityManagerInterface $entityManager,
        private readonly PinnedContainerRepository $pinRepository,
        private readonly PinnedContainerCatalog $catalog,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): PinnedContainerResource
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        \assert($data instanceof PinContainerInput);

        $asset = $this->catalog->visibleAssetOf($user, $data->itemId);
        if ($asset === null) {
            throw new NotFoundHttpException('Container not found');
        }

        if ($this->pinRepository->findByUserAndItemId($user, $data->itemId) === null) {
            $this->entityManager->persist(new PinnedContainer($user, $data->itemId, PinnedContainerCatalog::nameOf($asset)));
            $this->entityManager->flush();
        }

        foreach ($this->catalog->entriesOf($user) as $entry) {
            if ($entry->itemId === $data->itemId) {
                return PinnedContainerResourceMapper::of($entry);
            }
        }

        throw new NotFoundHttpException('Container not found');
    }
}
