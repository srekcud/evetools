<?php

declare(strict_types=1);

namespace App\State\Provider\Industry;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Industry\ScannerFavoriteResource;
use App\Entity\IndustryScannerFavorite;
use App\Entity\User;
use App\Repository\IndustryScannerFavoriteRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<ScannerFavoriteResource>
 */
class ScannerFavoriteCollectionProvider implements ProviderInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly IndustryScannerFavoriteRepository $favoriteRepository,
    ) {
    }

    /**
     * @return list<ScannerFavoriteResource>
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $entities = $this->favoriteRepository->findByUser($user);

        return array_map([$this, 'mapToResource'], $entities);
    }

    private function mapToResource(IndustryScannerFavorite $entity): ScannerFavoriteResource
    {
        $resource = new ScannerFavoriteResource();
        $resource->typeId = $entity->getTypeId();
        $resource->createdAt = $entity->getCreatedAt()->format(\DateTimeInterface::ATOM);

        return $resource;
    }
}
